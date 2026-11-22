<?php

declare(strict_types=1);

// Run against a newly created, empty disposable accounting_m1_test database only.
require dirname(__DIR__, 4).'/integration/backend/bootstrap.php';
require_once __DIR__.'/AccountingTestManager.php';

use App\Core\Utils\UUID;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Accounting\Migrations\Version20261121100000;
use NsUltimate\Business\Accounting\Migrations\Version20261122100000;
use NsUltimate\Business\Accounting\Migrations\Version20261122110000;
use NsUltimate\Business\Accounting\Migrations\Version20261122120000;
use NsUltimate\Business\Accounting\Migrations\Version20261122130000;
use NsUltimate\Business\Accounting\Migrations\Version20261122140000;
use NsUltimate\Business\Accounting\Migrations\Version20261122150000;
use NsUltimate\Business\Accounting\Migrations\Version20261122160000;
use NsUltimate\Business\Accounting\Service\LedgerCommands;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Repository\LedgerReadRepository;
use NsUltimate\Business\Accounting\Repository\LedgerWriteRepository;
use NsUltimate\Business\Accounting\Tests\AccountingTestManager;

function connect(): Connection
{
    $port = filter_var(getenv('ACCOUNTING_TEST_MYSQL_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
    if (!$port || !is_string($password = getenv('ACCOUNTING_TEST_MYSQL_PASSWORD'))) {
        throw new RuntimeException('Set the disposable MySQL test port and password.');
    }

    return DriverManager::getConnection([
        'driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => $port, 'dbname' => 'accounting_m1_test',
        'user' => 'accounting', 'password' => $password, 'charset' => 'utf8mb4',
    ]);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

const ACTOR = 'mysql-test-actor';
if (($argv[1] ?? null) === 'worker') {
    [$action, $payload] = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $db = connect();
    $db->fetchOne('SELECT 1');
    fwrite(STDOUT, "ready\n");
    try {
        $result = (new LedgerCommands(new LedgerWriteRepository(AccountingTestManager::create($db))))->execute(ACTOR, $action, $payload);
    } catch (LedgerError $error) {
        $result = ['error' => $error->getMessage()];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
    exit(0);
}

function worker(string $action, array $payload): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start test worker.');
    }
    fwrite($pipes[0], json_encode([$action, $payload], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    while (($line = fgets($pipes[1])) !== "ready\n") {
        if ($line === false) {
            throw new RuntimeException('Worker not ready.');
        }
    }

    return [$process, $pipes[1]];
}

function waitForLock(array $workers): void
{
    $read = array_column($workers, 1);
    $write = $except = null;
    check(stream_select($read, $write, $except, 0, 150000) === 0, 'A worker completed while the book was locked.');
}

function result(array $worker): array
{
    [$process, $stream] = $worker;
    $line = fgets($stream);
    fclose($stream);
    check(proc_close($process) === 0 && is_string($line), 'Worker failed.');

    return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
}

$db = connect();
check((int) $db->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === 0, 'Refusing to run against a non-empty database.');
AccountingTestManager::migrate($db, Version20261121100000::class);
check((int) $db->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine = 'InnoDB'") === 8, 'Expected eight InnoDB tables.');
AccountingTestManager::migrate($db, Version20261122100000::class, Version20261122110000::class, Version20261122120000::class, Version20261122130000::class);
$store = UUID::v4();
$db->insert('ns_accounting_book', [
    'id' => 2, 'store_uuid' => $store, 'entity' => 'Pre-upgrade book', 'currency' => 'CNY', 'scale' => 2,
    'timezone' => 'UTC', 'opening_date' => '2026-01-01', 'opening_state' => 'pending', 'posting_count' => 0,
]);
$oldDraft = UUID::v4();
$db->insert('ns_accounting_journal', ['book_id' => 2, 'id' => $oldDraft, 'accounting_date' => '2026-01-01',
    'origin' => 'manual', 'state' => 'draft', 'revision' => 3, 'summary' => 'Pre-upgrade draft']);
AccountingTestManager::migrate($db, Version20261122140000::class, Version20261122150000::class, Version20261122160000::class);
check((int) $db->fetchOne('SELECT book_id FROM ns_accounting_book_store WHERE store_uuid = ?', [$store]) === 2, 'Existing store binding was not preserved.');
$backfilled = $db->fetchAssociative('SELECT book_id, revision, store_uuid FROM ns_accounting_journal WHERE id = ?', [$oldDraft]);
check($backfilled !== false && (int) $backfilled['book_id'] === 2 && (int) $backfilled['revision'] === 3 && $backfilled['store_uuid'] === $store, 'Existing journal was not attributed in place.');
$em = AccountingTestManager::create($db);
$ormSchema = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
$schemaManager = $db->createSchemaManager();
$diff = $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $ormSchema);
$changes = $db->getDatabasePlatform()->getAlterSchemaSQL($diff);
check($changes === [], 'ORM mapping differs from the applied MySQL migration: '.implode('; ', $changes));
$commands = new LedgerCommands(new LedgerWriteRepository($em));
$run = fn (string $action, array $data) => $commands->execute(ACTOR, $action, $data);
$run('initialize', ['entity' => 'Synthetic book', 'currency' => 'CNY', 'scale' => 2, 'timezone' => 'UTC', 'opening_date' => '2026-01-01']);
foreach (['CASH' => 'asset', 'EQUITY' => 'equity'] as $code => $class) {
    $run('save-account', ['code' => $code, 'name' => $code, 'classification' => $class, 'normal_side' => 'debit', 'active' => true, 'postable' => true]);
}
$run('add-period', ['month' => '2026-01']);
$run('add-period', ['month' => '2026-02']);
$run('approve-zero-opening', ['reason' => 'Synthetic opening', 'command_id' => UUID::v4()]);
$draft = fn () => $run('create-draft', [
    'origin' => 'manual', 'accounting_date' => '2026-01-05', 'summary' => 'Synthetic', 'command_id' => UUID::v4(),
    'lines' => [['account_code' => 'CASH', 'side' => 'debit', 'amount' => '9223372036854775807'],
                ['account_code' => 'EQUITY', 'side' => 'credit', 'amount' => '9223372036854775807']],
]) + ['command_id' => UUID::v4()];
$a = $draft();
$b = $draft();
$c = $draft();

// Abort after assigning the number and writing the journal to prove atomic rollback.
$db->executeStatement("ALTER TABLE ns_accounting_audit ADD CONSTRAINT ns_accounting_test_fail CHECK (`action` <> 'post')");
try {
    $run('post', $a);
    throw new RuntimeException('Expected a database error.');
} catch (DriverException $e) {
    check(str_contains($e->getMessage(), 'ns_accounting_test_fail'), 'Unexpected database error.');
} finally {
    $db->executeStatement('ALTER TABLE ns_accounting_audit DROP CHECK ns_accounting_test_fail');
}
check((int) $db->fetchOne('SELECT posting_count FROM ns_accounting_book') === 0, 'Posting count was not rolled back.');
check((int) $db->fetchOne('SELECT COUNT(*) FROM ns_accounting_series') === 0, 'Series allocation was not rolled back.');

$db->beginTransaction();
try {
    $db->executeStatement('UPDATE ns_accounting_book SET posting_count = posting_count WHERE id = 1');
    $workers = [worker('post', $a), worker('post', $a), worker('post', $b)];
    waitForLock($workers);
} finally {
    $db->commit();
}
[$first, $retry, $second] = array_map('result', $workers);
check($first === $retry && isset($first['number'], $second['number']), 'Concurrent replay did not return the original posting.');
$numbers = [$first['number'], $second['number']];
sort($numbers);
check($numbers === ['2026-1', '2026-2'], 'Posting numbers were not unique.');
$q = new LedgerReadRepository($db);
check($q->report('trial', ['from' => '2026-01-01', 'to' => '2026-01-31'])['totals']['closing_debit'] === '18446744073709551614', 'MySQL aggregate lost precision.');

$db->beginTransaction();
try {
    $run('lock-period', ['month' => '2026-01', 'reason' => 'Close January', 'command_id' => UUID::v4()]);
    $worker = worker('post', $c);
    waitForLock([$worker]);
} finally {
    $db->commit();
}
check(result($worker) === ['error' => 'period_not_open'], 'Concurrent period lock did not stop posting.');

$reverse = ['id' => $a['id'], 'accounting_date' => '2026-02-01', 'reason' => 'Synthetic reversal', 'command_id' => UUID::v4()];
$db->beginTransaction();
try {
    $db->executeStatement('UPDATE ns_accounting_book SET posting_count = posting_count WHERE id = 1');
    $workers = [worker('reverse', $reverse), worker('reverse', $reverse)];
    waitForLock($workers);
} finally {
    $db->commit();
}
[$reversed, $replayed] = array_map('result', $workers);
check($reversed === $replayed && $reversed['number'] === '2026-3', 'Concurrent reversal did not replay.');
check((int) $db->fetchOne("SELECT COUNT(*) FROM ns_accounting_journal WHERE state = 'posted'") === 3, 'Unexpected posted count.');

$cashStore = UUID::v4();
$cash = ['source_uuid' => UUID::v4()];
// Simulate the Store table in the composed app with a different collation.
$db->executeStatement('CREATE TABLE store (uuid VARCHAR(36) PRIMARY KEY, name VARCHAR(255) NOT NULL) COLLATE utf8mb4_unicode_ci');
$db->insert('store', ['uuid' => $cashStore, 'name' => 'Synthetic store']);
$db->insert('ns_store_cash_entry', [
    'uuid' => $cash['source_uuid'], 'store_uuid' => $cashStore, 'actor_uuid' => UUID::v4(), 'entry_date' => '2026-02-02',
    'currency' => 'CNY', 'direction' => 'expense', 'category' => 'transport', 'amount' => 12000, 'note' => 'Freight',
    'metadata' => '{}', 'created_at' => '2026-02-02 12:00:00',
]);
$run('bind-store', ['store_uuid' => $cashStore]);
$run('save-cash-mapping', ['category' => 'transport', 'debit_code' => 'EQUITY', 'credit_code' => 'CASH']);
$mappingRows = $q->cashMappings(1, 1, 20)->items;
check(count($mappingRows) === 1 && $mappingRows[0]['status'] === 'cash_mapping_configured' && $q->cashMapping(1, 'transport')['credit_code'] === 'CASH', 'Cash mapping list did not read the configured category.');
$db->beginTransaction();
try {
    $db->executeStatement('UPDATE ns_accounting_book SET posting_count = posting_count WHERE id = 1');
    $workers = [worker('draft-from-cash', $cash), worker('draft-from-cash', $cash)];
    waitForLock($workers);
} finally {
    $db->commit();
}
[$fromCash, $duplicate] = array_map('result', $workers);
check(isset($fromCash['id'], $duplicate['id']) && $fromCash['id'] === $duplicate['id'] && $fromCash['existing'] !== $duplicate['existing'], 'Concurrent cash conversion did not deduplicate.');
$entries = $q->cashEntries(1, ['category' => 'transport'])->items;
check(count($entries) === 1 && $entries[0]['journal_id'] === $fromCash['id'] && $entries[0]['journal_state'] === 'draft' && $entries[0]['store_name'] === 'Synthetic store', 'Cash register did not return its linked draft.');
fwrite(STDOUT, "MySQL migration, ORM schema parity, rollback, exact report, posting replay, closing race, reversal replay, and concurrent cash draft generation passed.\n");
