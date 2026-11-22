<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add book-scoped cash categories independent of journal mappings.'; }

    public function up(Schema $schema): void
    {
        $addition = new Schema();
        $table = $addition->createTable('ns_accounting_cash_category');
        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
        $table->addColumn('book_id', 'integer');
        $table->addColumn('category', 'string', ['length' => 24]);
        $table->addColumn('direction', 'string', ['length' => 7]);
        $table->addColumn('name', 'string', ['length' => 120]);
        $table->setPrimaryKey(['book_id', 'category']);
        $table->addForeignKeyConstraint('ns_accounting_book', ['book_id'], ['id'], ['onDelete' => 'CASCADE']);
        foreach ($addition->toSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql("INSERT INTO ns_accounting_cash_category (book_id, category, direction, name)
            SELECT binding.book_id, e.category, MAX(e.direction), e.category
            FROM ns_store_cash_entry e JOIN ns_accounting_book_store binding ON HEX(binding.store_uuid) = HEX(e.store_uuid)
            GROUP BY binding.book_id, e.category");
        $this->addSql("INSERT INTO ns_accounting_cash_category (book_id, category, direction, name)
            SELECT m.book_id, m.category, CASE WHEN m.category = 'other_income' THEN 'income' ELSE 'expense' END, m.category
            FROM ns_accounting_cash_mapping m
            WHERE NOT EXISTS (
                SELECT 1 FROM ns_accounting_cash_category c
                WHERE c.book_id = m.book_id AND HEX(c.category) = HEX(m.category)
            )");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve configured cash categories.');
    }
}
