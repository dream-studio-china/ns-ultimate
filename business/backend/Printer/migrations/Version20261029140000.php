<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Timestamp order print rule claims for bounded retention.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE printer_order_rule_claim ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->addSql('CREATE INDEX idx_printer_order_rule_claim_created ON printer_order_rule_claim (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_printer_order_rule_claim_created ON printer_order_rule_claim');
        $this->addSql('ALTER TABLE printer_order_rule_claim DROP created_at');
    }
}
