<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename Counter tables to their canonical unprefixed names.';
    }

    public function up(Schema $schema): void
    {
        $legacyCounterExists = $schema->hasTable('business_counter');
        $counterExists = $schema->hasTable('counter');
        $legacyOrderCounterExists = $schema->hasTable('business_order_counter');
        $orderCounterExists = $schema->hasTable('counter_order');

        if ($legacyCounterExists && $counterExists) {
            throw new \RuntimeException('Both business_counter and counter exist; reconcile their data before migrating.');
        }
        if ($legacyOrderCounterExists && $orderCounterExists) {
            throw new \RuntimeException('Both business_order_counter and counter_order exist; reconcile their data before migrating.');
        }

        if ($legacyCounterExists) {
            $this->addSql('ALTER TABLE business_counter RENAME TO counter');
        }
        if ($legacyOrderCounterExists) {
            $this->addSql('ALTER TABLE business_order_counter RENAME TO counter_order');
        }

        $this->renameIndex(
            $schema,
            $legacyCounterExists ? 'business_counter' : 'counter',
            'counter',
            'uniq_business_counter_uuid',
            'uniq_counter_uuid',
            ['uuid'],
            true,
        );
        $this->renameIndex(
            $schema,
            $legacyCounterExists ? 'business_counter' : 'counter',
            'counter',
            'uniq_business_counter_store_code',
            'uniq_counter_store_code',
            ['store_uuid', 'code'],
            true,
        );
        $this->renameIndex(
            $schema,
            $legacyCounterExists ? 'business_counter' : 'counter',
            'counter',
            'idx_business_counter_store_enabled',
            'idx_counter_store_enabled',
            ['store_uuid', 'enabled'],
        );
        $this->renameIndex(
            $schema,
            $legacyOrderCounterExists ? 'business_order_counter' : 'counter_order',
            'counter_order',
            'uniq_business_order_counter_pair',
            'uniq_counter_order_pair',
            ['trade_order_uuid', 'counter_id'],
            true,
        );
        $this->renameIndex(
            $schema,
            $legacyOrderCounterExists ? 'business_order_counter' : 'counter_order',
            'counter_order',
            'idx_business_order_counter_store',
            'idx_counter_order_store',
            ['store_uuid'],
        );
    }

    public function down(Schema $schema): void
    {
        $counterExists = $schema->hasTable('counter');
        $legacyCounterExists = $schema->hasTable('business_counter');
        $orderCounterExists = $schema->hasTable('counter_order');
        $legacyOrderCounterExists = $schema->hasTable('business_order_counter');

        if ($counterExists && $legacyCounterExists) {
            throw new \RuntimeException('Both counter and business_counter exist; reconcile their data before reverting.');
        }
        if ($orderCounterExists && $legacyOrderCounterExists) {
            throw new \RuntimeException('Both counter_order and business_order_counter exist; reconcile their data before reverting.');
        }

        if ($orderCounterExists) {
            $this->addSql('ALTER TABLE counter_order RENAME TO business_order_counter');
        }
        if ($counterExists) {
            $this->addSql('ALTER TABLE counter RENAME TO business_counter');
        }

        $this->renameIndex(
            $schema,
            $orderCounterExists ? 'counter_order' : 'business_order_counter',
            'business_order_counter',
            'uniq_counter_order_pair',
            'uniq_business_order_counter_pair',
            ['trade_order_uuid', 'counter_id'],
            true,
        );
        $this->renameIndex(
            $schema,
            $orderCounterExists ? 'counter_order' : 'business_order_counter',
            'business_order_counter',
            'idx_counter_order_store',
            'idx_business_order_counter_store',
            ['store_uuid'],
        );
        $this->renameIndex(
            $schema,
            $counterExists ? 'counter' : 'business_counter',
            'business_counter',
            'uniq_counter_uuid',
            'uniq_business_counter_uuid',
            ['uuid'],
            true,
        );
        $this->renameIndex(
            $schema,
            $counterExists ? 'counter' : 'business_counter',
            'business_counter',
            'uniq_counter_store_code',
            'uniq_business_counter_store_code',
            ['store_uuid', 'code'],
            true,
        );
        $this->renameIndex(
            $schema,
            $counterExists ? 'counter' : 'business_counter',
            'business_counter',
            'idx_counter_store_enabled',
            'idx_business_counter_store_enabled',
            ['store_uuid', 'enabled'],
        );
    }

    /** @param list<string> $columns */
    private function renameIndex(
        Schema $schema,
        string $schemaTableName,
        string $targetTableName,
        string $oldName,
        string $newName,
        array $columns,
        bool $unique = false,
    ): void {
        if (!$schema->hasTable($schemaTableName)) {
            return;
        }

        $table = $schema->getTable($schemaTableName);
        if (!$table->hasIndex($oldName)) {
            return;
        }
        if ($table->hasIndex($newName)) {
            throw new \RuntimeException(sprintf(
                'Both indexes %s and %s exist on %s; reconcile the schema before migrating.',
                $oldName,
                $newName,
                $schemaTableName,
            ));
        }

        if ($this->platform instanceof AbstractMySQLPlatform) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX %s TO %s', $targetTableName, $oldName, $newName));
            return;
        }

        if ($this->platform instanceof SQLitePlatform) {
            $this->addSql(sprintf('DROP INDEX %s', $oldName));
            $uniqueSql = $unique ? 'UNIQUE ' : '';
            $this->addSql(sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $uniqueSql,
                $newName,
                $targetTableName,
                implode(', ', $columns),
            ));
            return;
        }

        throw new \RuntimeException(sprintf(
            'Index renaming is not implemented for database platform "%s".',
            $this->platform::class,
        ));
    }
}
