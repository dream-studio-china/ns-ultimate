<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller\Manage;

use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use NsUltimate\Business\Accounting\Controller\LedgerControllerSupport;
use NsUltimate\Business\Accounting\Controller\LedgerCsv;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Repository\LedgerReadRepository;
use NsUltimate\Business\Accounting\Repository\LedgerWriteRepository;
use NsUltimate\Business\Accounting\Service\LedgerAccess;
use NsUltimate\Business\Accounting\Service\LedgerAccountService;
use NsUltimate\Business\Accounting\Service\LedgerCommands;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\LedgerPage;
use NsUltimate\Business\Accounting\Service\LedgerWorkspace;
use NsUltimate\Business\Accounting\Service\StoreCashRegister;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Finance resources other than journals, plus the explicit command endpoint.
 *
 * Book, account, period, audit, and report reads return the standard EasyAdmin
 * list/detail envelopes. Resource writes call the ledger command service and
 * keep its book lock and audit; state changes stay explicit commands.
 */
final class LedgerController extends RestController
{
    use LedgerControllerSupport;

    private const COMMANDS = [
        'initialize' => 'configure', 'save-account' => 'configure', 'add-period' => 'configure', 'bind-store' => 'configure', 'unbind-store' => 'configure',
        'lock-period' => 'lock', 'reopen-period' => 'reopen', 'approve-zero-opening' => 'post',
        'create-draft' => 'draft', 'update-draft' => 'draft', 'void-draft' => 'draft',
        'post' => 'post', 'reverse' => 'reverse',
        'save-cash-mapping' => 'configure', 'create-cash-mapping' => 'configure', 'delete-cash-mapping' => 'configure',
        'create-cash-category' => 'configure', 'update-cash-category' => 'configure', 'delete-cash-category' => 'configure',
        'draft-from-cash' => 'draft', 'correct-cash-entry' => 'cash_record', 'void-cash-entry' => 'cash_record',
    ];
    private const ACCOUNT_CODE = '[A-Z0-9][A-Z0-9._-]{0,31}';
    private const MONTH = '[1-9][0-9]{3}-(?:0[1-9]|1[0-2])';

    public function __construct(
        private readonly LedgerAccess $access,
        private readonly LedgerCommands $commands,
        private readonly LedgerReadRepository $queries,
        private readonly LedgerWorkspace $workspaceService,
        private readonly LedgerAccountService $accounts,
        private readonly StoreServiceInterface $stores,
        private readonly StoreCashRegister $cash,
        private readonly LedgerWriteRepository $writes,
    ) {}

    #[Route('/workspace', name: 'workspace', methods: ['GET'])]
    public function workspace(Request $request): Response
    {
        return $this->respond('read', function (User $user, array $capabilities) use ($request): array {
            LedgerInput::fields($this->ledgerParameters($request));

            $id = $this->headquartersBookId();
            $workspace = $this->workspaceService->setup($id);
            if ($id !== AccountingBook::ID && $workspace['book'] === null) {
                throw new LedgerError('book_not_found', 404);
            }

            return $workspace + ['capabilities' => $capabilities, 'store_uuids' => $this->workspaceService->stores($id)]
                + (array_intersect(['cash_record', 'configure'], $capabilities) !== [] ? ['cash_stores' => $this->workspaceService->cashStores($id)] : [])
                + (array_intersect(['cash_record', 'configure'], $capabilities) !== [] ? ['cash_categories' => $this->workspaceService->cashCategories($id)] : [])
                + (in_array('configure', $capabilities, true) ? ['mapped_cash_categories' => $this->workspaceService->mappedCashCategories($id)] : []);
        });
    }

    #[Route('/available-stores', name: 'available_stores', methods: ['GET'])]
    public function availableStores(Request $request): Response
    {
        return $this->respond('configure', function () use ($request): Response {
            $params = $this->ledgerParameters($request);
            LedgerInput::fields($params, 'q');
            $search = LedgerInput::text($params['q'] ?? '', 160, true);

            return $this->success((object) ['stores' => $this->workspaceService->availableStores($search)]);
        });
    }

    #[Route('/cash-entries', name: 'cash_entries', methods: ['GET'])]
    public function cashEntries(Request $request): Response
    {
        return $this->respond('read', fn (User $user, array $capabilities) => $this->queries->cashEntries(
            $this->headquartersBookId(), $this->ledgerParameters($request), in_array('cash_record', $capabilities, true),
        ));
    }

    #[Route('/cash-mappings', name: 'cash_mappings_list', methods: ['GET'])]
    public function cashMappings(Request $request): Response
    {
        return $this->respond('read', function () use ($request): LedgerPage {
            $params = $this->ledgerParameters($request);
            LedgerInput::fields($params, 'page', 'limit');
            [$page, $limit] = LedgerPage::window($params);

            return $this->queries->cashMappings($this->headquartersBookId(), $page, $limit);
        });
    }

    #[Route('/cash-categories', name: 'cash_categories', methods: ['GET'])]
    public function cashCategories(Request $request): Response
    {
        return $this->respond('read', function () use ($request): LedgerPage {
            $params = $this->ledgerParameters($request);
            LedgerInput::fields($params, 'page', 'limit');
            [$page, $limit] = LedgerPage::window($params);

            return $this->queries->cashCategories($this->headquartersBookId(), $page, $limit);
        });
    }

    #[Route('/cash-mappings/{category}', name: 'cash_mappings_detail', requirements: ['category' => '[a-z][a-z0-9_]{0,23}'], methods: ['GET'])]
    public function cashMapping(string $category): Response
    {
        return $this->respond('read', function () use ($category): array {
            return $this->queries->cashMapping($this->headquartersBookId(), $category);
        });
    }

    #[Route('/cash-mappings', name: 'cash_mappings_create', methods: ['POST'])]
    public function createCashMapping(Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($request): Response {
            $data = $this->ledgerPayload($request);
            $result = $this->commands->execute($user->getUuid(), 'create-cash-mapping', $data, $this->headquartersBookId());

            return $this->success((object) $this->queries->cashMapping($this->headquartersBookId(), $result['id']), status: 201);
        });
    }

    #[Route('/cash-mappings/{category}', name: 'cash_mappings_update', requirements: ['category' => '[a-z][a-z0-9_]{0,23}'], methods: ['PUT'])]
    public function updateCashMapping(string $category, Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($category, $request): array {
            $data = $this->ledgerPayload($request);
            if (array_key_exists('category', $data) && $data['category'] !== $category) {
                throw new LedgerError('invalid_option');
            }
            $this->commands->execute($user->getUuid(), 'save-cash-mapping', ['category' => $category] + $data, $this->headquartersBookId());

            return $this->queries->cashMapping($this->headquartersBookId(), $category);
        });
    }

    #[Route('/cash-mappings/{category}', name: 'cash_mappings_delete', requirements: ['category' => '[a-z][a-z0-9_]{0,23}'], methods: ['DELETE'])]
    public function deleteCashMapping(string $category, Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($category): array {
            $this->commands->execute($user->getUuid(), 'delete-cash-mapping', ['category' => $category], $this->headquartersBookId());

            return ['id' => $category];
        });
    }

    #[Route('/cash-categories/{category}', name: 'cash_category', requirements: ['category' => '[a-z][a-z0-9_]{0,23}'], methods: ['GET'])]
    public function cashCategory(string $category, Request $request): Response
    {
        return $this->respond('read', function () use ($category, $request): array {
            LedgerInput::fields($this->ledgerParameters($request));
            foreach ($this->workspaceService->cashCategories($this->headquartersBookId()) as $row) {
                if ($row['id'] === $category) return $row;
            }
            throw new LedgerError('cash_category_not_found', 404);
        });
    }

    #[Route('/cash-categories', name: 'cash_category_create', methods: ['POST'])]
    public function createCashCategory(Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($request): Response {
            $data = $this->ledgerPayload($request);
            $data['category'] = 'c_'.bin2hex(random_bytes(11));
            $result = $this->commands->execute($user->getUuid(), 'create-cash-category', $data, $this->headquartersBookId());
            return $this->success((object) $this->cashCategoryRecord($result['id']), status: 201);
        });
    }

    #[Route('/cash-categories/{category}', name: 'cash_category_update', requirements: ['category' => '[a-z][a-z0-9_]{0,23}'], methods: ['PUT'])]
    public function updateCashCategory(string $category, Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($category, $request): array {
            $data = $this->ledgerPayload($request);
            if (isset($data['category']) && $data['category'] !== $category) throw new LedgerError('invalid_option');
            $this->commands->execute($user->getUuid(), 'update-cash-category', ['category' => $category] + $data, $this->headquartersBookId());
            return $this->cashCategoryRecord($category);
        });
    }

    #[Route('/cash-categories/{category}', name: 'cash_category_delete', requirements: ['category' => '[a-z][a-z0-9_]{0,23}'], methods: ['DELETE'])]
    public function deleteCashCategory(string $category): Response
    {
        return $this->respond('configure', function (User $user) use ($category): array {
            return $this->commands->execute($user->getUuid(), 'delete-cash-category', ['category' => $category], $this->headquartersBookId());
        });
    }

    /** @return array{id: string, name: string, direction: string} */
    private function cashCategoryRecord(string $category): array
    {
        foreach ($this->workspaceService->cashCategories($this->headquartersBookId()) as $row) {
            if ($row['id'] === $category) return $row;
        }
        throw new LedgerError('cash_category_not_found', 404);
    }

    #[Route('/cash-entries', name: 'cash_entries_create', methods: ['POST'])]
    public function recordCashEntry(Request $request): Response
    {
        return $this->respond('cash_record', function (User $user) use ($request): Response {
            $data = $this->ledgerPayload($request);
            LedgerInput::fields($data, 'store_uuid', 'command_id', 'date', 'direction', 'category', 'amount', 'note', 'metadata');
            $uuid = LedgerInput::uuid($data['store_uuid'] ?? null);
            if (array_key_exists('metadata', $data)) {
                if (!$data['metadata'] instanceof \stdClass) {
                    throw new LedgerError('invalid_metadata');
                }
                $data['metadata'] = get_object_vars($data['metadata']);
                if (($data['metadata']['extra'] ?? null) instanceof \stdClass) {
                    $data['metadata']['extra'] = get_object_vars($data['metadata']['extra']);
                }
            }
            $data['commandUuid'] = LedgerInput::uuid($data['command_id'] ?? null);
            unset($data['command_id'], $data['store_uuid']);
            $bookId = $this->headquartersBookId();
            $entry = $this->writes->transactional(function () use ($user, $data, $uuid, $bookId): array {
                $this->writes->lockBook($bookId);
                if ($this->writes->storeBinding($uuid)?->getBook()->getId() !== $bookId) {
                    throw new LedgerError('store_not_in_book', 409);
                }
                $store = $this->stores->get(['uuid' => $uuid], false);
                if (!$store instanceof Store) {
                    throw new LedgerError('store_not_found', 404);
                }

                return $this->cash->record($store, $user->getUuid(), $data);
            });

            return $this->success((object) ['id' => $entry['uuid']], status: 201);
        });
    }

    #[Route('/books', name: 'books_list', methods: ['GET'])]
    public function books(Request $request): Response
    {
        return $this->respond('read', function () use ($request): LedgerPage {
            $parameters = $this->ledgerParameters($request);
            LedgerInput::fields($parameters, 'page', 'limit');
            [$page, $limit] = LedgerPage::window($parameters);
            return $this->workspaceService->books($page, $limit);
        });
    }

    #[Route('/books/{id}', name: 'books_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function book(int $id, Request $request): Response
    {
        return $this->respond('read', function () use ($id, $request): array {
            LedgerInput::fields($this->ledgerParameters($request));

            return $this->findBook($id);
        });
    }

    #[Route('/books', name: 'books_create', methods: ['POST'])]
    public function createBook(Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($request): Response {
            $data = $this->ledgerPayload($request);
            $id = AccountingBook::ID;
            $uuid = null;
            if (isset($data['store_uuid'])) {
                $store = $this->stores->get(['uuid' => LedgerInput::uuid($data['store_uuid'])], false);
                if (!$store instanceof Store || $store->getId() === null || $store->getId() >= 2147483647) {
                    throw new LedgerError('store_not_found', 404);
                }
                // Book 1 is reserved for the unassigned legacy book.
                $id = $store->getId() + 1;
                $uuid = $store->getUuid();
            }
            unset($data['store_uuid']);
            $result = $this->commands->execute($user->getUuid(), 'initialize', $data, $id, $uuid);

            return $this->success((object) $this->findBook($result['id']), status: 201);
        });
    }

    #[Route('/ledger-accounts', name: 'accounts_list', methods: ['GET'])]
    public function accounts(Request $request): Response
    {
        return $this->respond('read', function () use ($request): Response {
            $this->listParameters($request, 'q');
            $query = LedgerInput::text($request->query->all()['q'] ?? '', 160, true);
            $rows = array_filter($this->accounts->chart($this->headquartersBookId()), static fn (array $row) =>
                $query === '' || mb_stripos($row['code'].' '.$row['name'], $query) !== false);

            return $this->success($this->sortRows(array_map($this->accountRecord(...), $rows), $request, ['code', 'name', 'classification', 'normal_side', 'active', 'postable']));
        });
    }

    #[Route('/ledger-accounts/{code}', name: 'accounts_detail', requirements: ['code' => self::ACCOUNT_CODE], methods: ['GET'])]
    public function account(string $code, Request $request): Response
    {
        return $this->respond('read', function () use ($code, $request): array {
            LedgerInput::fields($this->ledgerParameters($request));

            return $this->findAccount($code);
        });
    }

    #[Route('/ledger-accounts', name: 'accounts_create', methods: ['POST'])]
    public function createAccount(Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($request): Response {
            $result = $this->execute($user, 'create-account', $request);

            return $this->success((object) $this->findAccount($result['id']), status: 201);
        });
    }

    #[Route('/ledger-accounts/{code}', name: 'accounts_update', requirements: ['code' => self::ACCOUNT_CODE], methods: ['PUT'])]
    public function updateAccount(string $code, Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($code, $request): array {
            $data = $this->ledgerPayload($request);
            if (array_key_exists('code', $data) && $data['code'] !== $code) {
                throw new LedgerError('invalid_account_code');
            }
            $this->commands->execute($user->getUuid(), 'update-account', ['code' => $code] + $data, $this->headquartersBookId());

            return $this->findAccount($code);
        });
    }

    #[Route('/accounting-periods', name: 'periods_list', methods: ['GET'])]
    public function periods(Request $request): Response
    {
        return $this->respond('read', function () use ($request): Response {
            $this->listParameters($request);

            $rows = array_map(static fn (array $row) => ['id' => $row['month']] + $row, $this->workspaceService->periods($this->headquartersBookId()));

            return $this->success($this->sortRows($rows, $request, ['month', 'state']));
        });
    }

    #[Route('/accounting-periods/{month}', name: 'periods_detail', requirements: ['month' => self::MONTH], methods: ['GET'])]
    public function period(string $month, Request $request): Response
    {
        return $this->respond('read', function () use ($month, $request): array {
            LedgerInput::fields($this->ledgerParameters($request));
            $period = $this->workspaceService->period($month, $this->headquartersBookId()) ?? throw new LedgerError('period_not_found', 404);

            return ['id' => $month] + $period;
        });
    }

    #[Route('/accounting-periods', name: 'periods_create', methods: ['POST'])]
    public function createPeriod(Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($request): Response {
            $result = $this->execute($user, 'add-period', $request);

            return $this->success((object) ['id' => $result['id'], 'month' => $result['id'], 'state' => 'open'], status: 201);
        });
    }

    #[Route('/audit', name: 'audit', methods: ['GET'])]
    public function audit(Request $request): Response
    {
        return $this->respond('audit', fn () => $this->queries->audit($this->ledgerParameters($request), $this->headquartersBookId()));
    }

    #[Route('/audit/{id}', name: 'audit_detail', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function auditRecord(string $id, Request $request): Response
    {
        return $this->respond('audit', function () use ($id, $request): array {
            LedgerInput::fields($this->ledgerParameters($request));

            return $this->queries->auditRecord($id, $this->headquartersBookId());
        });
    }

    #[Route('/reports/{kind}/csv', name: 'export', methods: ['GET'])]
    public function export(string $kind, Request $request): Response
    {
        return $this->respond('read', function () use ($kind, $request): Response {
            $data = $this->queries->report($kind, $this->ledgerParameters($request), true, $this->headquartersBookId());

            return LedgerCsv::response($kind, $data);
        });
    }

    /**
     * Report rows as a standard list page. The snapshot context (cutoff, book
     * currency and scale, opening balance or trial totals) is returned in
     * `report` so later pages and the CSV export can request the same cutoff.
     */
    #[Route('/reports/{kind}', name: 'report', methods: ['GET'])]
    public function report(string $kind, Request $request): Response
    {
        return $this->respond('read', function () use ($kind, $request): LedgerPage {
            $data = $this->queries->report($kind, $this->ledgerParameters($request), bookId: $this->headquartersBookId());
            $context = array_intersect_key($data, array_flip(['cutoff', 'currency', 'scale', 'opening', 'totals']));

            return new LedgerPage($data['rows'], $data['total'], $data['page'], $data['limit'], ['report' => ['kind' => $kind] + $context]);
        });
    }
    #[Route('/commands/{action}', name: 'command', methods: ['POST'])]
    public function command(string $action, Request $request): Response
    {
        return $this->respond(self::COMMANDS[$action] ?? null, fn (User $user) => $this->execute($user, $action, $request));
    }

    /** @return array<string, mixed> */
    private function execute(User $user, string $action, Request $request): array
    {
        if ($action === 'initialize' && $request->attributes->has('bookId')) {
            throw new LedgerError('unknown_command', 404);
        }

        $data = $this->ledgerPayload($request);
        if ($action === 'bind-store') {
            $uuid = LedgerInput::uuid($data['store_uuid'] ?? null);
            if (!$this->stores->get(['uuid' => $uuid], false) instanceof Store) {
                throw new LedgerError('store_not_found', 404);
            }
        }

        return $this->commands->execute($user->getUuid(), $action, $data, $this->headquartersBookId());
    }

    private function listParameters(Request $request, string ...$extra): void
    {
        $parameters = $this->ledgerParameters($request);
        LedgerInput::fields($parameters, 'page', 'limit', '@order', ...$extra);
        LedgerPage::window($parameters);
    }

    /** @param array<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, Request $request, array $fields): array
    {
        $order = $request->query->all()['@order'] ?? null;
        if ($order === null) {
            return array_values($rows);
        }
        if (!is_string($order) || !preg_match('/\Aentity\.([a-z_]+)\|(ASC|DESC)\z/', $order, $matches)
            || !in_array($matches[1], $fields, true)) {
            throw new LedgerError('invalid_sort');
        }
        $field = $matches[1];
        $direction = $matches[2] === 'ASC' ? 1 : -1;
        usort($rows, static function (array $left, array $right) use ($field, $direction): int {
            $a = $left[$field];
            $b = $right[$field];
            $order = is_string($a) && is_string($b) ? strcmp($a, $b) : $a <=> $b;

            return $direction * $order;
        });

        return $rows;
    }

    /** @return array<string, mixed> */
    private function findBook(int|string $id): array
    {
        $book = $this->workspaceService->book((int) $id);
        if ($book === null || (string) $book['id'] !== (string) $id) {
            throw new LedgerError('book_not_found', 404);
        }

        return $book + ['store_uuids' => $this->workspaceService->stores((int) $id)];
    }

    /** @return array<string, mixed> */
    private function findAccount(string $code): array
    {
        $account = $this->accounts->record($code, $this->headquartersBookId());

        return $this->accountRecord($account ?? throw new LedgerError('account_not_found', 404));
    }

    /** @param array<string, int|string> $account
     * @return array<string, mixed>
     */
    private function accountRecord(array $account): array
    {
        return ['id' => $account['code'], 'active' => (bool) $account['active'], 'postable' => (bool) $account['postable']] + $account;
    }

    private function respond(?string $capability, callable $operation): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new LedgerError('authentication_required', 401);
        }
        if ($capability === null) {
            throw new LedgerError('unknown_command', 404);
        }
        $capabilities = $this->access->capabilities($user);
        if (!in_array('read', $capabilities, true) || !in_array($capability, $capabilities, true)) {
            throw new LedgerError('finance_access_required', 403);
        }
        $result = $operation($user, $capabilities);

        // Command results and single records are objects so the Core reply helper does not paginate them.
        return $result instanceof Response ? $result : $this->success($result instanceof LedgerPage ? $result : (object) $result);
    }
}
