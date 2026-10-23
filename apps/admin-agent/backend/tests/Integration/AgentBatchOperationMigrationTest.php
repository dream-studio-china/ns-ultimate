<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Apps\AdminAgent\Backend\Migrations\Version20261023120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../migrations/Version20261023120000.php';

final class AgentBatchOperationMigrationTest extends TestCase
{
    public function testCreatesAndDropsOnlyTheBatchOperationTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE common_tag (id INTEGER PRIMARY KEY, slug VARCHAR(255) NOT NULL)');
        $connection->executeStatement("INSERT INTO common_tag VALUES (1, 'existing-tag')");
        $migration = new Version20261023120000($connection, new NullLogger());
        $migration->up(new Schema());
        self::assertNotEmpty($migration->getSql());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertTrue($connection->createSchemaManager()->tablesExist(['admin_agent_batch_operation']));
        $columns = $connection->createSchemaManager()->listTableColumns('admin_agent_batch_operation');
        self::assertArrayHasKey('proposal_rows', $columns);
        self::assertArrayNotHasKey('rows', $columns);
        $migration = new Version20261023120000($connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertFalse($connection->createSchemaManager()->tablesExist(['admin_agent_batch_operation']));
        self::assertSame('existing-tag', $connection->fetchOne('SELECT slug FROM common_tag WHERE id = 1'));
    }
}
