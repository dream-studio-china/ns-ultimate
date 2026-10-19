<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Business\Common\Migrations\Version20261019110000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../migrations/Version20261019110000.php';

final class ContentSortOrderMigrationTest extends TestCase
{
    public function testMigrationPreservesContentAndDefaultsExistingRowsToBusinessSubtype(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE common_content (id INTEGER PRIMARY KEY, title VARCHAR(255) NOT NULL)');
        $connection->executeStatement("INSERT INTO common_content (id, title) VALUES (1, 'Existing content')");

        $migration = new Version20261019110000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame([
            'id' => 1,
            'title' => 'Existing content',
            'content_type' => 'common',
            'sort_order' => 0,
        ], $connection->fetchAssociative('SELECT * FROM common_content WHERE id = 1'));

        $connection->executeStatement("INSERT INTO common_content (id, title) VALUES (2, 'Core content')");
        self::assertSame('core', $connection->fetchOne('SELECT content_type FROM common_content WHERE id = 2'));

        $migration = new Version20261019110000($connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame([
            ['id' => 1, 'title' => 'Existing content'],
            ['id' => 2, 'title' => 'Core content'],
        ], $connection->fetchAllAssociative('SELECT * FROM common_content ORDER BY id'));
    }
}
