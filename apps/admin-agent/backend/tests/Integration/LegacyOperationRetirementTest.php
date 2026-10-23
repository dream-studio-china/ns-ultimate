<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use NsUltimate\Apps\AdminAgent\Backend\Command\PruneAgentOperationsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LegacyOperationRetirementTest extends TestCase
{
    public function testRetirementErasesPendingValuesWithoutRemovingHistoricalReceipts(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['admin_agent_operation' => 'changes', 'admin_agent_batch_operation' => 'proposal_rows'] as $table => $column) {
            $connection->executeStatement(sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY, %s TEXT, status TEXT, completed_at TEXT)', $table, $column));
            $connection->insert($table, ['id' => 1, $column => '{"private":"draft"}', 'status' => 'pending']);
            $connection->insert($table, ['id' => 2, $column => '[]', 'status' => 'succeeded']);
        }
        $tester = new CommandTester(new PruneAgentOperationsCommand($connection));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Retired 2 legacy proposal(s)', $tester->getDisplay());
        foreach (['admin_agent_operation' => 'changes', 'admin_agent_batch_operation' => 'proposal_rows'] as $table => $column) {
            self::assertSame('[]', $connection->fetchOne(sprintf('SELECT %s FROM %s WHERE id = 1', $column, $table)));
            self::assertSame('cancelled', $connection->fetchOne(sprintf('SELECT status FROM %s WHERE id = 1', $table)));
            self::assertSame('succeeded', $connection->fetchOne(sprintf('SELECT status FROM %s WHERE id = 2', $table)));
            self::assertSame(2, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table)));
        }
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Retired 0 legacy proposal(s)', $tester->getDisplay());
    }

    public function testFreshDatabaseWithoutLegacyTablesNeedsNoOperationMigration(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tester = new CommandTester(new PruneAgentOperationsCommand($connection));
        self::assertSame(0, $tester->execute([]));
    }
}
