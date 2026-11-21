<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Tests;

use App\Core\Utils\UUID;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Business\Accounting\Migrations\Version20261121100000;
use NsUltimate\Business\Accounting\Service\LedgerCommands;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Repository\LedgerReadRepository;
use NsUltimate\Business\Accounting\Repository\LedgerWriteRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LedgerTest extends TestCase
{
    private Connection $db;
    private LedgerCommands $commands;
    private LedgerReadRepository $queries;

    protected function setUp(): void
    {
        require_once dirname(__DIR__).'/migrations/Version20261121100000.php';
        require_once __DIR__.'/AccountingTestManager.php';
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        $migration = new Version20261121100000($this->db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) {
            $this->db->executeStatement($sql->getStatement());
        }
        $this->commands = new LedgerCommands(new LedgerWriteRepository(AccountingTestManager::create($this->db)));
        $this->queries = new LedgerReadRepository($this->db);
        $this->runCommand('initialize', [
            'entity' => 'Synthetic book', 'currency' => 'CNY', 'scale' => 2, 'timezone' => 'Asia/Shanghai', 'opening_date' => '2026-01-01',
        ]);
        $this->account('CASH', 'asset', 'debit');
        $this->account('EXPENSE', 'expense', 'debit');
        $this->account('EQUITY', 'equity', 'credit');
        $this->runCommand('add-period', ['month' => '2026-01']);
        $this->runCommand('add-period', ['month' => '2026-02']);
    }

    public function testManualLedgerOpeningPostingClosingAndHistoricalReversal(): void
    {
        $opening = $this->draft('10000', '2026-01-01', 'CASH', 'EQUITY', 'opening');
        self::assertSame('2026-1', $this->post($opening)['number']);
        $expense = $this->draft('2000', '2026-01-02', 'EXPENSE', 'CASH');
        $parameters = ['from' => '2026-01-01', 'to' => '2026-02-28'];
        $before = $this->queries->report('trial', $parameters);
        self::assertSame('10000', $before['totals']['movement_debit']);
        $postInput = $expense + ['command_id' => UUID::v4()];
        $posted = $this->runCommand('post', $postInput);
        self::assertSame('2026-2', $posted['number']);
        self::assertSame($posted, $this->runCommand('post', $postInput));
        $this->assertError('command_key_conflict', fn () => $this->runCommand('post', array_replace($postInput, ['revision' => 2])));
        $this->assertError('stale_draft', fn () => $this->runCommand('void-draft', $expense + ['command_id' => UUID::v4(), 'reason' => 'Correction']));
        $cash = $this->queries->report('ledger', ['account' => 'CASH'] + $parameters);
        self::assertSame(['10000', '8000'], array_column($cash['rows'], 'balance'));
        self::assertSame($before, $this->queries->report('trial', $parameters + ['cutoff' => $before['cutoff']]));
        $backdated = $this->draft('1', '2026-01-03', 'EXPENSE', 'CASH');
        $this->runCommand('lock-period', ['month' => '2026-01', 'reason' => 'Month end', 'command_id' => UUID::v4()]);
        $this->assertError('period_not_open', fn () => $this->post($backdated));
        $this->account('EXPENSE', 'expense', 'debit', false);
        $reverseInput = ['id' => $expense['id'], 'accounting_date' => '2026-02-01', 'reason' => 'Incorrect expense', 'command_id' => UUID::v4()];
        $reversed = $this->runCommand('reverse', $reverseInput);
        self::assertSame('2026-3', $reversed['number']);
        self::assertSame($reversed, $this->runCommand('reverse', $reverseInput));
        self::assertSame($expense['id'], $this->queries->journal($reversed['id'], true)['reversal_of']);
        self::assertSame($reversed['id'], $this->queries->journal($expense['id'], false)['reversed_by']);
        self::assertSame('posted', $this->queries->journal($expense['id'], false)['state']);
        $closing = $this->queries->report('balances', ['from' => '2026-02-01', 'to' => '2026-02-28', 'account' => 'CASH']);
        self::assertSame('8000', $closing['rows'][0]['opening_debit']);
        self::assertSame('10000', $closing['rows'][0]['closing_debit']);
        $this->runCommand('reopen-period', ['month' => '2026-01', 'reason' => 'Approved reopen', 'command_id' => UUID::v4()]);
        $this->assertError('account_not_postable', fn () => $this->post($backdated));
        self::assertSame('draft', $this->queries->journal($backdated['id'], false)['state']);
    }

    public function testExactMoneyValidationAndZeroOpening(): void
    {
        $this->runCommand('approve-zero-opening', ['reason' => 'New entity', 'command_id' => UUID::v4()]);
        $max = '9223372036854775807';
        $this->post($this->draft($max, '2026-01-01', 'CASH', 'EQUITY'));
        $this->post($this->draft($max, '2026-01-01', 'CASH', 'EQUITY'));
        $statement = $this->queries->report('trial', ['from' => '2026-01-01', 'to' => '2026-01-31']);
        self::assertSame('18446744073709551614', $statement['totals']['closing_debit']);
        self::assertSame($statement['totals']['closing_debit'], $statement['totals']['closing_credit']);
        $this->assertError('invalid_amount', fn () => $this->draft('9223372036854775808', '2026-01-01', 'CASH', 'EQUITY'));
        $this->assertError('invalid_amount', fn () => $this->draft('0', '2026-01-01', 'CASH', 'EQUITY'));
        $this->assertError('unexpected_field', fn () => $this->runCommand('initialize', ['posting_count' => 1]));
        $this->assertError('opening_state_conflict', fn () => $this->post($this->draft('1', '2026-01-01', 'CASH', 'EQUITY', 'opening')));
        $draft = $this->draft('1', '2026-01-02', 'CASH', 'EQUITY');
        $payload = $draft + ['accounting_date' => '2026-01-02', 'summary' => 'Unbalanced', 'lines' => [
            ['account_code' => 'CASH', 'side' => 'debit', 'amount' => '2'],
            ['account_code' => 'EQUITY', 'side' => 'credit', 'amount' => '1'],
        ]];
        $revised = $this->runCommand('update-draft', $payload);
        $this->assertError('stale_draft', fn () => $this->runCommand('update-draft', $payload));
        $this->assertError('unbalanced_journal', fn () => $this->post($revised));
        self::assertSame('2', $this->queries->report('trial', ['from' => '2026-01-01', 'to' => '2026-01-31'])['cutoff']);
    }

    public function testBookRetryAndDraftLineReplacementPreserveExistingData(): void
    {
        $book = ['entity' => 'Synthetic book', 'currency' => 'CNY', 'scale' => 2, 'timezone' => 'Asia/Shanghai', 'opening_date' => '2026-01-01'];
        self::assertSame(['id' => 1], $this->runCommand('initialize', $book));
        $this->assertError('book_already_initialized', fn () => $this->runCommand('initialize', array_replace($book, ['currency' => 'USD'])));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM ns_accounting_audit WHERE action = 'initialize'"));

        $draft = $this->draft('300', '2026-01-02', 'CASH', 'EQUITY');
        $updated = $this->runCommand('update-draft', $draft + [
            'accounting_date' => '2026-01-03', 'summary' => 'Replacement', 'lines' => [
                ['account_code' => 'EXPENSE', 'side' => 'debit', 'amount' => '200'],
                ['account_code' => 'CASH', 'side' => 'credit', 'amount' => '200'],
            ],
        ]);
        self::assertSame(2, $updated['revision']);
        $read = $this->queries->journal($draft['id'], true);
        self::assertSame('Fixture approval', $read['evidence']);
        self::assertSame(['EXPENSE', 'CASH'], array_column($read['lines'], 'account_code'));
        self::assertSame(['200', '200'], array_column($read['lines'], 'amount'));
        $this->runCommand('approve-zero-opening', ['reason' => 'Empty opening', 'command_id' => UUID::v4()]);
        self::assertSame('2026-1', $this->post($updated)['number']);
    }

    private function account(string $code, string $class, string $side, bool $active = true): void
    {
        $this->runCommand('save-account', [
            'code' => $code, 'name' => $code, 'classification' => $class, 'normal_side' => $side, 'active' => $active, 'postable' => true,
        ]);
    }

    private function draft(string $amount, string $date, string $debit, string $credit, string $origin = 'manual'): array
    {
        return $this->runCommand('create-draft', [
            'origin' => $origin, 'accounting_date' => $date, 'summary' => 'Synthetic journal', 'evidence' => 'Fixture approval',
            'command_id' => UUID::v4(), 'lines' => [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
            ],
        ]);
    }

    private function post(array $target): array
    {
        return $this->runCommand('post', $target + ['command_id' => UUID::v4()]);
    }

    private function runCommand(string $action, array $data): array
    {
        return $this->commands->execute('synthetic-actor', $action, $data);
    }

    private function assertError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected '.$code);
        } catch (LedgerError $error) {
            self::assertSame($code, $error->getMessage());
        }
    }
}
