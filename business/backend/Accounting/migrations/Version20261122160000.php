<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Keep voided store cash entries for audit instead of deleting them.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ns_store_cash_entry ADD COLUMN voided_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ns_store_cash_entry ADD COLUMN voided_by VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE ns_store_cash_entry ADD COLUMN void_reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve voided store cash history.');
    }
}
