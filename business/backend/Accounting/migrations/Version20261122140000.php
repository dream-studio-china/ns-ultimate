<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Preserve existing store books and attribute journals for shared books.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $addition = new Schema();
        $table = $addition->createTable('ns_accounting_book_store', ['engine' => 'InnoDB', 'charset' => 'utf8mb4']);
        $table->addColumn('store_uuid', 'string', ['length' => 36]);
        $table->addColumn('book_id', 'integer');
        $table->setPrimaryKey(['store_uuid']);
        $table->addIndex(['book_id'], 'idx_acct_book_store_book');
        $table->addForeignKeyConstraint('ns_accounting_book', ['book_id'], ['id']);
        foreach ($addition->toSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('INSERT INTO ns_accounting_book_store (store_uuid, book_id) SELECT store_uuid, id FROM ns_accounting_book WHERE store_uuid IS NOT NULL');
        $this->addSql('ALTER TABLE ns_accounting_journal ADD COLUMN store_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE ns_accounting_journal SET store_uuid = (SELECT b.store_uuid FROM ns_accounting_book b WHERE b.id = ns_accounting_journal.book_id) WHERE store_uuid IS NULL');
        $this->addSql('CREATE INDEX idx_acct_journal_store ON ns_accounting_journal (book_id, store_uuid, accounting_date)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve journal attribution and store book bindings.');
    }
}
