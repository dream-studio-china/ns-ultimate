<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;

final class AdminAgentRetirementMigrationTest extends TestCase
{
    private const RETIREMENT = 'NsUltimate\\Integration\\Backend\\Migrations\\Version20261024120000';

    private function factory(Connection $connection): DependencyFactory
    {
        return DependencyFactory::fromConnection(new ConfigurationArray([
            'migrations_paths' => [
                'NsUltimate\\Apps\\AdminAgent\\Backend\\Migrations' => dirname(__DIR__).'/legacy-migrations/admin-agent',
                'NsUltimate\\Integration\\Backend\\Migrations' => dirname(__DIR__).'/migrations',
            ],
        ]), new ExistingConnection($connection));
    }

    private function migrate(DependencyFactory $factory, string $target): void
    {
        $factory->getMetadataStorage()->ensureInitialized();
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($target));
        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    public function testFreshMigrationChainEndsWithoutAssistantTablesAndPreservesBusinessData(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE business_sentinel (id INTEGER PRIMARY KEY, title TEXT)');
        $connection->insert('business_sentinel', ['id' => 1, 'title' => 'Preserved']);
        $factory = $this->factory($connection);
        $this->migrate($factory, self::RETIREMENT);
        self::assertSame(3, $factory->getMetadataStorage()->getExecutedMigrations()->count());
        self::assertFalse($connection->createSchemaManager()->tablesExist(['admin_agent_operation']));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['admin_agent_batch_operation']));
        self::assertSame('Preserved', $connection->fetchOne('SELECT title FROM business_sentinel WHERE id = 1'));
        $this->migrate($factory, self::RETIREMENT);
        self::assertSame(3, $factory->getMetadataStorage()->getExecutedMigrations()->count());
    }

    public function testPreviouslyAppliedVersionsRemainRecognizedAndRetirementDropsAllLegacyData(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $factory = $this->factory($connection);
        $this->migrate($factory, 'NsUltimate\\Apps\\AdminAgent\\Backend\\Migrations\\Version20261023120000');
        self::assertTrue($connection->createSchemaManager()->tablesExist(['admin_agent_operation', 'admin_agent_batch_operation']));
        $connection->executeStatement("INSERT INTO admin_agent_operation (id, actor, action, entity_key, before_hash, changes, fields, status, created_at, expires_at) VALUES ('old', 'actor', 'create', 'Content', 'hash', '{\"private\":true}', '[]', 'pending', '2026-10-22', '2026-10-23')");
        // A new factory simulates boot after moving the original migration files.
        $upgraded = $this->factory($connection);
        $plan = $upgraded->getMigrationPlanCalculator()->getPlanUntilVersion(new Version(self::RETIREMENT));
        self::assertCount(1, $plan->getItems());
        $this->migrate($upgraded, self::RETIREMENT);
        self::assertFalse($connection->createSchemaManager()->tablesExist(['admin_agent_operation']));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['admin_agent_batch_operation']));
        self::assertSame(3, $upgraded->getMetadataStorage()->getExecutedMigrations()->count());
    }

    public function testRetirementToleratesMissingTablesAndCannotPretendToRecoverDeletedReceipts(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $factory = $this->factory($connection);
        $migration = $factory->getMigrationRepository()->getMigrations()->getMigration(new Version(self::RETIREMENT))->getMigration();
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
        self::assertSame([], $connection->createSchemaManager()->listTableNames());
        $this->expectException(IrreversibleMigration::class);
        $migration->down(new Schema());
    }
}
