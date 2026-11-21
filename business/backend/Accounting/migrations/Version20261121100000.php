<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Install the isolated manual accounting ledger without seeding financial data.';
    }

    public function isTransactional(): bool
    {
        return false; // MySQL DDL commits implicitly.
    }

    public function up(Schema $schema): void
    {
        foreach ($this->initialSchema()->toSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve accounting history; use forward migrations.');
    }

    private function initialSchema(): Schema
    {
        $schema = new Schema();
        $table = self::table($schema, 'book', [
            'id' => 'integer', 'entity' => 160, 'currency' => 3, 'scale' => 'integer',
            'timezone' => 80, 'opening_date' => 'date', 'opening_state' => 12, 'posting_count' => 'bigint',
        ], ['id']);
        $table = self::table($schema, 'period', ['book_id' => 'integer', 'month' => 7, 'state' => 12], ['book_id', 'month']);
        self::book($table);
        $table = self::table($schema, 'account', [
            'book_id' => 'integer', 'code' => 32, 'name' => 160, 'classification' => 16,
            'normal_side' => 6, 'active' => 'boolean', 'postable' => 'boolean',
        ], ['book_id', 'code']);
        self::book($table);
        $table = self::table($schema, 'journal', [
            'book_id' => 'integer', 'id' => 36, 'accounting_date' => 'date', 'origin' => 12,
            'state' => 12, 'revision' => 'integer', 'summary' => 240, 'evidence' => '?text',
            'reversal_of' => '?36', 'series_year' => '?integer', 'sequence_no' => '?bigint',
            'posting_index' => '?bigint', 'posted_at' => '?32', 'posted_by' => '?64',
        ], ['book_id', 'id']);
        self::book($table);
        $table->addUniqueIndex(['book_id', 'series_year', 'sequence_no'], 'uniq_acct_series');
        $table->addUniqueIndex(['book_id', 'posting_index'], 'uniq_acct_posting_index');
        $table->addUniqueIndex(['book_id', 'reversal_of'], 'uniq_acct_reversal');
        $table->addIndex(['book_id', 'accounting_date', 'sequence_no', 'id'], 'idx_acct_journal_date');
        $table->addForeignKeyConstraint('ns_accounting_journal', ['book_id', 'reversal_of'], ['book_id', 'id']);
        $table = self::table($schema, 'line', [
            'book_id' => 'integer', 'journal_id' => 36, 'position' => 'integer', 'account_code' => 32,
            'side' => 6, 'amount' => 'bigint', 'description' => 240,
        ], ['book_id', 'journal_id', 'position']);
        $table->addForeignKeyConstraint('ns_accounting_journal', ['book_id', 'journal_id'], ['book_id', 'id']);
        $table->addForeignKeyConstraint('ns_accounting_account', ['book_id', 'account_code'], ['book_id', 'code']);
        $table->addIndex(['book_id', 'account_code', 'journal_id'], 'idx_acct_account_lines');
        $table = self::table($schema, 'series', ['book_id' => 'integer', 'series_year' => 'integer', 'last_no' => 'bigint'], ['book_id', 'series_year']);
        self::book($table);
        $table = self::table($schema, 'receipt', [
            'book_id' => 'integer', 'actor' => 64, 'action' => 32, 'command_id' => 36,
            'fingerprint' => 64, 'result' => 'text',
        ], ['book_id', 'actor', 'action', 'command_id']);
        self::book($table);
        $table = self::table($schema, 'audit', [
            'book_id' => 'integer', 'id' => 36, 'actor' => 64, 'action' => 32, 'target' => 64,
            'occurred_at' => 32, 'correlation_id' => '?36', 'detail' => 'text',
        ], ['book_id', 'id']);
        self::book($table);
        $table->addIndex(['book_id', 'occurred_at', 'id'], 'idx_acct_audit_time');

        return $schema;
    }

    /**
     * @param array<string, string|int> $fields
     * @param non-empty-list<string> $key
     */
    private static function table(Schema $schema, string $name, array $fields, array $key): Table
    {
        $table = $schema->createTable('ns_accounting_'.$name);
        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
        foreach ($fields as $column => $type) {
            $type = (string) $type;
            $required = !str_starts_with($type, '?');
            $type = ltrim($type, '?');
            $options = ['notnull' => $required];
            if (ctype_digit($type)) {
                $options['length'] = (int) $type;
                $type = 'string';
            }
            $table->addColumn($column, $type, $options);
        }
        $table->setPrimaryKey($key);

        return $table;
    }

    private static function book(Table $table): void
    {
        $table->addForeignKeyConstraint('ns_accounting_book', ['book_id'], ['id']);
    }
}
