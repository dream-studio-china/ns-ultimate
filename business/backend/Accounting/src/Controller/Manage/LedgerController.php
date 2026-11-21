<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller\Manage;

use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use NsUltimate\Business\Accounting\Repository\LedgerReadRepository;
use NsUltimate\Business\Accounting\Service\LedgerAccess;
use NsUltimate\Business\Accounting\Service\LedgerAccountService;
use NsUltimate\Business\Accounting\Service\LedgerCommands;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\LedgerWorkspace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LedgerController extends RestController
{
    private const COMMANDS = [
        'initialize' => 'configure', 'save-account' => 'configure', 'add-period' => 'configure',
        'lock-period' => 'lock', 'reopen-period' => 'reopen', 'approve-zero-opening' => 'post',
        'create-draft' => 'draft', 'update-draft' => 'draft', 'void-draft' => 'draft',
        'post' => 'post', 'reverse' => 'reverse',
    ];

    public function __construct(
        private readonly LedgerAccess $access,
        private readonly LedgerCommands $commands,
        private readonly LedgerReadRepository $queries,
        private readonly LedgerWorkspace $workspaceService,
        private readonly LedgerAccountService $accounts,
    ) {}

    #[Route('/workspace', name: 'workspace', methods: ['GET'])]
    public function workspace(Request $request): Response
    {
        return $this->respond('read', function (User $user, array $capabilities) use ($request): array {
            LedgerInput::fields($this->parameters($request));

            return $this->workspaceService->setup() + ['capabilities' => $capabilities];
        });
    }

    #[Route('/ledger-accounts', name: 'accounts_list', methods: ['GET'])]
    public function accounts(Request $request): Response
    {
        return $this->respond('read', function () use ($request): Response {
            $this->listParameters($request, true);
            $query = LedgerInput::text($request->query->all()['q'] ?? '', 160, true);
            $rows = array_filter($this->accounts->chart(), static fn (array $row) =>
                $query === '' || mb_stripos($row['code'].' '.$row['name'], $query) !== false);

            return $this->success($this->sortRows(array_map($this->accountRecord(...), $rows), $request, ['code', 'name', 'classification', 'normal_side', 'active', 'postable']));
        });
    }

    #[Route('/ledger-accounts/{code}', name: 'accounts_detail', requirements: ['code' => '[A-Z0-9][A-Z0-9._-]{0,31}'], methods: ['GET'])]
    public function account(string $code, Request $request): Response
    {
        return $this->respond('read', function () use ($code, $request): array {
            LedgerInput::fields($this->parameters($request));

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

    #[Route('/ledger-accounts/{code}', name: 'accounts_update', requirements: ['code' => '[A-Z0-9][A-Z0-9._-]{0,31}'], methods: ['PUT'])]
    public function updateAccount(string $code, Request $request): Response
    {
        return $this->respond('configure', function (User $user) use ($code, $request): array {
            $data = $this->payload($request);
            if (array_key_exists('code', $data) && $data['code'] !== $code) {
                throw new LedgerError('invalid_account_code');
            }
            $this->commands->execute($user->getUuid(), 'update-account', ['code' => $code] + $data);

            return $this->findAccount($code);
        });
    }

    #[Route('/accounting-periods', name: 'periods_list', methods: ['GET'])]
    public function periods(Request $request): Response
    {
        return $this->respond('read', function () use ($request): Response {
            $this->listParameters($request);

            $rows = array_map(static fn (array $row) => ['id' => $row['month']] + $row, $this->workspaceService->periods());

            return $this->success($this->sortRows($rows, $request, ['month', 'state']));
        });
    }

    #[Route('/accounting-periods/{month}', name: 'periods_detail', requirements: ['month' => '[1-9][0-9]{3}-(?:0[1-9]|1[0-2])'], methods: ['GET'])]
    public function period(string $month, Request $request): Response
    {
        return $this->respond('read', function () use ($month, $request): array {
            LedgerInput::fields($this->parameters($request));
            $period = $this->workspaceService->period($month) ?? throw new LedgerError('period_not_found', 404);

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
        return $this->respond('audit', fn () => $this->queries->audit($this->parameters($request)));
    }

    #[Route('/reports/{kind}/csv', name: 'export', methods: ['GET'])]
    public function export(string $kind, Request $request): Response
    {
        return $this->respond('read', function () use ($kind, $request): Response {
            $data = $this->queries->report($kind, $this->parameters($request), true);
            $stream = fopen('php://temp', 'w+') ?: throw new \RuntimeException('Cannot allocate CSV stream.');
            $rows = $data['rows'];
            $columns = $kind === 'ledger'
                ? ['id', 'accounting_date', 'number', 'summary', 'position', 'side', 'amount', 'description', 'balance']
                : ['code', 'name', 'opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'closing_debit', 'closing_credit'];
            $context = ['currency' => $data['currency'], 'scale' => $data['scale'], 'cutoff' => $data['cutoff']];
            if ($kind === 'ledger') {
                $context['opening'] = $data['opening'];
            }
            fputcsv($stream, array_merge($columns, array_keys($context)), escape: '');
            if (isset($data['totals'])) {
                $rows[] = ['name' => 'TOTAL'] + $data['totals'];
            }
            if ($rows === []) {
                $rows[] = [];
            }
            foreach ($rows as $row) {
                $cells = array_map(static function (string $column) use ($row): string {
                    $value = (string) ($row[$column] ?? '');
                    if (in_array($column, ['summary', 'description', 'name'], true)
                        && preg_match('/\A(?:[\t\r\n]|[\p{Z}\p{C}]*[=+\-@＝＋－＠])/u', $value)) {
                        return "'".$value;
                    }

                    return $value;
                }, $columns);
                fputcsv($stream, array_merge($cells, array_values($context)), escape: '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);

            return new Response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="accounting-'.$kind.'.csv"',
                'X-Content-Type-Options' => 'nosniff',
            ]);        });
    }

    #[Route('/reports/{kind}', name: 'report', methods: ['GET'])]
    public function report(string $kind, Request $request): Response
    {
        return $this->respond('read', fn () => $this->queries->report($kind, $this->parameters($request)));
    }

    #[Route('/commands/{action}', name: 'command', methods: ['POST'])]
    public function command(string $action, Request $request): Response
    {
        return $this->respond(self::COMMANDS[$action] ?? null, fn (User $user) => $this->execute($user, $action, $request));
    }

    /** @return array<string, mixed> */
    private function execute(User $user, string $action, Request $request): array
    {
        return $this->commands->execute($user->getUuid(), $action, $this->payload($request));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        LedgerInput::fields($this->parameters($request));
        if ($request->getContentTypeFormat() !== 'json') {
            throw new LedgerError('json_required', 415);
        }
        $body = $request->getContent();
        if (strlen($body) > 65536) {
            throw new LedgerError('body_too_large', 413);
        }
        try {
            $object = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
            if (!is_object($object) || (isset($object->lines) && !is_array($object->lines))) {
                throw new LedgerError('invalid_body');
            }
            return json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LedgerError('invalid_body');
        }
    }

    private function listParameters(Request $request, bool $search = false): void
    {
        $params = $this->parameters($request);
        LedgerInput::fields($params, ...($search ? ['page', 'limit', '@order', 'q'] : ['page', 'limit', '@order']));
        LedgerInput::page($params['page'] ?? '1');
        if (LedgerInput::page($params['limit'] ?? '20') > 300) {
            throw new LedgerError('invalid_page');
        }
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
    private function findAccount(string $code): array
    {
        $account = $this->accounts->record($code);

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

        // Finance projections own their pagination; do not paginate their envelope again.
        return $result instanceof Response ? $result : $this->success((object) $result);
    }

    /** @return array<string, mixed> */
    private function parameters(Request $request): array
    {
        $all = $request->query->all();
        unset($all['_locale']);

        return $all;
    }
}
