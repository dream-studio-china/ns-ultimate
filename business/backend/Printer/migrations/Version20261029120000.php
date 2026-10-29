<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add store-owned automatic order printing rules.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE printer_order_rule (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, store_uuid VARCHAR(36) NOT NULL, name VARCHAR(120) NOT NULL, enabled TINYINT(1) NOT NULL, events JSON NOT NULL, match_mode VARCHAR(3) NOT NULL, conditions JSON NOT NULL, scene VARCHAR(20) NOT NULL, device_uuid VARCHAR(36) NOT NULL, template_uuid VARCHAR(36) NOT NULL, copies INT NOT NULL, UNIQUE INDEX uniq_printer_order_rule_uuid (uuid), INDEX idx_printer_order_rule_store_enabled (store_uuid, enabled), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE printer_order_rule'); }
}
