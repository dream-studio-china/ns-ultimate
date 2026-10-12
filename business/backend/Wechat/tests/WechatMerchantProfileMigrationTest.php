<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests;

require_once __DIR__.'/../migrations/Version20261012130000.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Query\Query;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Business\Wechat\Migrations\Version20261012130000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WechatMerchantProfileMigrationTest extends TestCase
{
    public function testNullableProviderColumnsAreNormalizedIndependently(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE wechat_merchant (service_provider_app_id VARCHAR(128) DEFAULT NULL, sub_merchant_id VARCHAR(64) DEFAULT NULL)');
        $connection->insert('wechat_merchant', ['service_provider_app_id' => null, 'sub_merchant_id' => 'sub-existing']);
        $connection->insert('wechat_merchant', ['service_provider_app_id' => 'app-existing', 'sub_merchant_id' => null]);

        $migration = new Version20261012130000($connection, new NullLogger());
        $migration->up(new Schema());
        $normalization = $migration->getSql()[0];
        self::assertInstanceOf(Query::class, $normalization);
        $connection->executeStatement($normalization->getStatement());

        self::assertSame([
            ['service_provider_app_id' => '', 'sub_merchant_id' => 'sub-existing'],
            ['service_provider_app_id' => 'app-existing', 'sub_merchant_id' => ''],
        ], $connection->fetchAllAssociative('SELECT service_provider_app_id, sub_merchant_id FROM wechat_merchant ORDER BY rowid'));
        $connection->close();
    }

    public function testDowngradeAbortsBeforeDroppingCompositeUniquenessWhenMerchantIdsRepeat(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE wechat_merchant (merchant_id VARCHAR(64) NOT NULL)');
        $connection->insert('wechat_merchant', ['merchant_id' => 'provider-mch']);
        $connection->insert('wechat_merchant', ['merchant_id' => 'provider-mch']);
        $migration = new Version20261012130000($connection, new NullLogger());

        try {
            $migration->down(new Schema());
            self::fail('Expected downgrade to abort while duplicate merchant IDs exist.');
        } catch (AbortMigration) {
            self::assertSame([], $migration->getSql());
        } finally {
            $connection->close();
        }
    }
}
