<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use NsUltimate\Business\Wechat\Migrations\Version20261013100000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../migrations/Version20261013100000.php';

final class WechatOptionalMerchantMigrationTest extends TestCase
{
    public function testUpgradeMakesStoreMerchantNullable(): void
    {
        $migration = new Version20261013100000(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new NullLogger());
        $migration->up(new Schema());

        self::assertSame(
            ['ALTER TABLE store_wechat_setting MODIFY merchant_id INT DEFAULT NULL'],
            array_map(static fn ($query) => $query->getStatement(), $migration->getSql()),
        );
    }

    public function testDowngradeRestoresRequiredMerchantWhenNoNullRowsExist(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE store_wechat_setting (id INTEGER PRIMARY KEY, merchant_id INTEGER DEFAULT NULL)');
        $migration = new Version20261013100000($connection, new NullLogger());
        $migration->down(new Schema());

        self::assertSame(
            ['ALTER TABLE store_wechat_setting MODIFY merchant_id INT NOT NULL'],
            array_map(static fn ($query) => $query->getStatement(), $migration->getSql()),
        );
    }

    public function testDowngradeRejectsSettingsWithoutMerchants(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE store_wechat_setting (id INTEGER PRIMARY KEY, merchant_id INTEGER DEFAULT NULL)');
        $connection->executeStatement('INSERT INTO store_wechat_setting (merchant_id) VALUES (NULL)');
        $migration = new Version20261013100000($connection, new NullLogger());

        $this->expectException(AbortMigration::class);
        $migration->down(new Schema());
    }
}
