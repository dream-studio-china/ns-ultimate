<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use App\Core\Utils\UUID;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124100000 extends AbstractMigration
{
    private const TABLES = [
        'ns_accounting_book' => ['id'],
        'ns_accounting_period' => ['book_id', 'month'],
        'ns_accounting_account' => ['book_id', 'code'],
        'ns_accounting_line' => ['book_id', 'journal_id', 'position'],
        'ns_accounting_series' => ['book_id', 'series_year'],
        'ns_accounting_receipt' => ['book_id', 'actor', 'action', 'command_id'],
        'ns_accounting_cash_mapping' => ['book_id', 'category'],
        'ns_accounting_cash_category' => ['book_id', 'category'],
    ];

    public function getDescription(): string { return 'Add independent UUIDs to accounting records without changing existing keys.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $keys) {
            if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
                continue;
            }
            $columns = $this->connection->createSchemaManager()->listTableColumns($table);
            if (isset($columns['uuid'])) {
                continue;
            }
            // Execute DDL now: addSql() is deferred until after up(), when the
            // backfill below would otherwise query a column that does not exist.
            $this->connection->executeStatement("ALTER TABLE $table ADD uuid VARCHAR(36) DEFAULT NULL");
            $quotedKeys = array_map(static fn (string $key): string => $key === 'action' ? '`action`' : $key, $keys);
            $rows = $this->connection->fetchAllAssociative("SELECT ".implode(', ', $quotedKeys)." FROM $table");
            foreach ($rows as $row) {
                $where = [];
                $params = [];
                foreach ($keys as $key) {
                    $column = $key;
                    $where[] = ($key === 'action' ? '`action`' : $key).' = ?';
                    $params[] = $row[$column];
                }
                $this->connection->executeStatement("UPDATE $table SET uuid = ? WHERE ".implode(' AND ', $where), [UUID::v4(), ...$params]);
            }
            // SQLite cannot alter a column's nullability in place. A trigger
            // enforces the same invariant there; MySQL receives a NOT NULL column.
            if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
                $this->connection->executeStatement("CREATE TRIGGER trg_".substr($table, 3)."_uuid_nn_insert BEFORE INSERT ON $table WHEN NEW.uuid IS NULL BEGIN SELECT RAISE(ABORT, 'uuid is required'); END");
                $this->connection->executeStatement("CREATE TRIGGER trg_".substr($table, 3)."_uuid_nn_update BEFORE UPDATE OF uuid ON $table WHEN NEW.uuid IS NULL BEGIN SELECT RAISE(ABORT, 'uuid is required'); END");
            } else {
                $this->connection->executeStatement("ALTER TABLE $table MODIFY uuid VARCHAR(36) NOT NULL");
            }
            $this->connection->executeStatement("CREATE UNIQUE INDEX uniq_".substr($table, 3)."_uuid ON $table (uuid)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve assigned accounting UUIDs.');
    }
}
