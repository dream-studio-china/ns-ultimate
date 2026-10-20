<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Business\Store\Migrations\Version20261020100000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../migrations/Version20261020100000.php';

final class ProductSettingsMigrationTest extends TestCase
{
    public function testUpgradeAddsDefaultsForExistingProductsAndRollbackKeepsRows(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE trade_product (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        $connection->executeStatement("INSERT INTO trade_product (id, name) VALUES (1, 'Existing product')");

        $this->execute(new Version20261020100000($connection, new NullLogger()), $connection, 'up');

        self::assertSame([
            'id' => 1,
            'name' => 'Existing product',
            'recommendation_level' => 0,
            'is_sold_out' => 0,
            'settings' => null,
        ], $connection->fetchAssociative('SELECT * FROM trade_product WHERE id = 1'));

        $this->execute(new Version20261020100000($connection, new NullLogger()), $connection, 'down');

        self::assertSame(['id' => 1, 'name' => 'Existing product'], $connection->fetchAssociative('SELECT * FROM trade_product WHERE id = 1'));
    }

    private function execute(Version20261020100000 $migration, \Doctrine\DBAL\Connection $connection, string $direction): void
    {
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
