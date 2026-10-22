<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Apps\AdminAgent\Backend\Migrations\Version20261022120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../migrations/Version20261022120000.php';

final class AgentOperationMigrationTest extends TestCase
{
    public function testCreatesAndDropsOnlyTheOperationTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE common_content (id INTEGER PRIMARY KEY, title VARCHAR(255) NOT NULL)');
        $connection->executeStatement("INSERT INTO common_content VALUES (1, 'Existing content')");
        $migration = new Version20261022120000($connection, new NullLogger());
        $migration->up(new Schema());
        self::assertNotEmpty($migration->getSql());
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
        self::assertTrue($connection->createSchemaManager()->tablesExist(['admin_agent_operation']));
        $migration = new Version20261022120000($connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
        self::assertFalse($connection->createSchemaManager()->tablesExist(['admin_agent_operation']));
        self::assertSame('Existing content', $connection->fetchOne('SELECT title FROM common_content WHERE id = 1'));
    }
}
