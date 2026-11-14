<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use NsUltimate\Business\Printer\Migrations\Version20261029160000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../migrations/Version20261029160000.php';

final class PrinterItemFilterMigrationTest extends TestCase
{
    public function testUpgradeExpandsStorageWithoutRewritingExistingFilters(): void
    {
        $migration = new Version20261029160000(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new NullLogger());
        $migration->up(new Schema());

        self::assertSame(
            ['ALTER TABLE printer_template MODIFY item_filter LONGTEXT DEFAULT NULL'],
            array_map(static fn ($query) => $query->getStatement(), $migration->getSql()),
        );
    }
}
