<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Deduplicate automatic printing per order and rule.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE printer_order_rule_claim (claim_key VARCHAR(64) NOT NULL, token VARCHAR(36) NOT NULL, PRIMARY KEY(claim_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE printer_order_rule_claim'); }
}
