<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Allow one accounting book per store without reassigning the existing book.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ns_accounting_book ADD COLUMN store_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_acct_book_store ON ns_accounting_book (store_uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve accounting book ownership and history.');
    }
}
