<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use NsUltimate\Business\Wechat\Migrations\Version20261012110000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../migrations/Version20261012110000.php';

final class WechatIdentityMigrationTest extends TestCase
{
    public function testUpgradeCreatesReplacementIndexBeforeDroppingForeignKeyIndex(): void
    {
        $migration = new Version20261012110000(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new NullLogger());
        $migration->up(new Schema());

        self::assertSame([
            'CREATE INDEX IDX_724F26ADA76ED395 ON store_wechat_identity (user_id)',
            'DROP INDEX uniq_store_wechat_identity_user ON store_wechat_identity',
        ], array_map(static fn ($query) => $query->getStatement(), $migration->getSql()));
    }

    public function testDowngradeCreatesUniqueIndexBeforeDroppingForeignKeyIndex(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE store_wechat_identity (user_id INTEGER NOT NULL)');
        $migration = new Version20261012110000($connection, new NullLogger());
        $migration->down(new Schema());

        self::assertSame([
            'CREATE UNIQUE INDEX uniq_store_wechat_identity_user ON store_wechat_identity (user_id)',
            'DROP INDEX IDX_724F26ADA76ED395 ON store_wechat_identity',
        ], array_map(static fn ($query) => $query->getStatement(), $migration->getSql()));
    }

    public function testDowngradeRejectsUsersWithMultipleIdentities(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE store_wechat_identity (user_id INTEGER NOT NULL)');
        $connection->executeStatement('INSERT INTO store_wechat_identity (user_id) VALUES (1), (1)');
        $migration = new Version20261012110000($connection, new NullLogger());

        $this->expectException(AbortMigration::class);
        $migration->down(new Schema());
    }
}
