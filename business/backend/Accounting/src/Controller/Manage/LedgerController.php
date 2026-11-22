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
use NsUltimate\Business\Accounting\Service\LedgerAccess;
use NsUltimate\Business\Accounting\Service\LedgerAccountService;
use NsUltimate\Business\Accounting\Service\LedgerCommands;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\LedgerPage;
use NsUltimate\Business\Accounting\Service\LedgerWorkspace;
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
        'initialize' => 'configure', 'save-account' => 'configure', 'add-period' => 'configure', 'bind-store' => 'configure',
        'lock-period' => 'lock', 'reopen-period' => 'reopen', 'approve-zero-opening' => 'post',
        'create-draft' => 'draft', 'update-draft' => 'draft', 'void-draft' => 'draft',
        'post' => 'post', 'reverse' => 'reverse',
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

            return $workspace + ['capabilities' => $capabilities, 'store_uuids' => $this->workspaceService->stores($id)];
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
