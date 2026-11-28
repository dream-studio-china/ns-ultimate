<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Business\Counter\Migrations\Version20261128120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../migrations/Version20261128120000.php';

final class CounterMergeCollationMigrationTest extends TestCase
{
    public function testOnlyMismatchedUuidColumnsAreChanged(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $db->expects(self::once())->method('fetchAllKeyValue')
            ->willReturn(['source_order_uuid' => 'utf8mb4_0900_ai_ci', 'target_order_uuid' => 'utf8mb4_unicode_ci']);
        $migration = new Version20261128120000($db, new NullLogger());
        $migration->up(new Schema());
        $sql = $migration->getSql();

        self::assertCount(1, $sql);
        self::assertSame(
            'ALTER TABLE counter_order_merge MODIFY COLUMN source_order_uuid VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
            $sql[0]->getStatement(),
        );
    }

    public function testSqliteDoesNotRequireCollationDdl(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261128120000($db, new NullLogger());
        $migration->up(new Schema());
        self::assertSame([], $migration->getSql());
    }
}
