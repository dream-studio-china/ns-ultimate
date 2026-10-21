<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable order-to-printer dispatch requests.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE printer_order_request (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, request_key VARCHAR(64) NOT NULL, order_uuid VARCHAR(36) NOT NULL, store_uuid VARCHAR(36) NOT NULL, case_key VARCHAR(64) NOT NULL, event_trigger VARCHAR(20) NOT NULL, configuration JSON NOT NULL, print_data JSON NOT NULL, status VARCHAR(20) NOT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_printer_order_request_key (request_key), UNIQUE INDEX uniq_printer_order_request_uuid (uuid), INDEX idx_printer_order_request_pending (status, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE printer_order_request');
    }
}
