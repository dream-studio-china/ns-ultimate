<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add printer accounts, devices, templates, and durable print jobs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE printer_account (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, name VARCHAR(120) NOT NULL, developer_id VARCHAR(160) NOT NULL, credentials_cipher LONGTEXT NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_printer_account_uuid (uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE printer_device (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, account_id INT NOT NULL, store_uuid VARCHAR(36) DEFAULT NULL, name VARCHAR(120) NOT NULL, serial_number VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL, model VARCHAR(80) DEFAULT NULL, width_mm INT DEFAULT NULL, height_mm INT DEFAULT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, provider_status INT DEFAULT NULL, status_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_printer_device_uuid (uuid), UNIQUE INDEX uniq_printer_device_account_sn (account_id, serial_number), INDEX idx_printer_device_store_kind (store_uuid, kind), INDEX IDX_PRINTER_DEVICE_ACCOUNT (account_id), PRIMARY KEY(id), CONSTRAINT FK_PRINTER_DEVICE_ACCOUNT FOREIGN KEY (account_id) REFERENCES printer_account (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE printer_template (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, name VARCHAR(120) NOT NULL, kind VARCHAR(20) NOT NULL, definition JSON NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_printer_template_uuid (uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE printer_job (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, device_id INT NOT NULL, store_uuid VARCHAR(36) DEFAULT NULL, kind VARCHAR(20) NOT NULL, copies INT NOT NULL, template_snapshot JSON NOT NULL, data_snapshot JSON NOT NULL, rendered_content LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, provider_order_id VARCHAR(80) DEFAULT NULL, error_code VARCHAR(80) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', idempotency_key VARCHAR(50) NOT NULL, UNIQUE INDEX uniq_printer_job_uuid (uuid), UNIQUE INDEX uniq_printer_job_idempotency (idempotency_key), INDEX idx_printer_job_store_created (store_uuid, created_at), INDEX IDX_PRINTER_JOB_DEVICE (device_id), PRIMARY KEY(id), CONSTRAINT FK_PRINTER_JOB_DEVICE FOREIGN KEY (device_id) REFERENCES printer_device (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE printer_job');
        $this->addSql('DROP TABLE printer_template');
        $this->addSql('DROP TABLE printer_device');
        $this->addSql('DROP TABLE printer_account');
    }
}
