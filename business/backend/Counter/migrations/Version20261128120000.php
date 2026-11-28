<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128120000 extends AbstractMigration
{
    private const COLLATION = 'utf8mb4_unicode_ci';

    public function getDescription(): string
    {
        return 'Align merge order UUIDs with the Trade order UUID collation on MySQL.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $collations = $this->connection->fetchAllKeyValue(
            "SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'counter_order_merge'
             AND COLUMN_NAME IN ('source_order_uuid', 'target_order_uuid')"
        );
        $changes = [];
        foreach (['source_order_uuid', 'target_order_uuid'] as $column) {
            $this->abortIf(!isset($collations[$column]), 'Missing merge UUID column: '.$column);
            if ($collations[$column] !== self::COLLATION) {
                $changes[] = sprintf('MODIFY COLUMN %s VARCHAR(36) CHARACTER SET utf8mb4 COLLATE %s NOT NULL', $column, self::COLLATION);
            }
        }
        if ($changes !== []) {
            $this->addSql('ALTER TABLE counter_order_merge '.implode(', ', $changes));
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->throwIrreversibleMigrationException('The original UUID collations depend on the database defaults at creation time.');
        }
    }
}
