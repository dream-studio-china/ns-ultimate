<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add manual cash journal mappings and immutable source references.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $addition = new Schema();
        $table = $addition->createTable('ns_accounting_cash_mapping');
        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
        $table->addColumn('book_id', 'integer');
        $table->addColumn('category', 'string', ['length' => 24]);
        $table->addColumn('debit_code', 'string', ['length' => 32]);
        $table->addColumn('credit_code', 'string', ['length' => 32]);
        $table->setPrimaryKey(['book_id', 'category']);
        $table->addForeignKeyConstraint('ns_accounting_book', ['book_id'], ['id']);
        foreach ($addition->toSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('ALTER TABLE ns_accounting_journal ADD COLUMN cash_source_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_acct_cash_source ON ns_accounting_journal (cash_source_uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve cash journal source history.');
    }
}
