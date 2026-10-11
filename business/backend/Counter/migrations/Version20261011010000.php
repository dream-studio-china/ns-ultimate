<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create store counters and trade-order counter bindings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE business_counter (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, store_uuid VARCHAR(36) NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(120) NOT NULL, capacity INT DEFAULT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_business_counter_uuid (uuid), UNIQUE INDEX uniq_business_counter_store_code (store_uuid, code), INDEX idx_business_counter_store_enabled (store_uuid, enabled), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE business_order_counter (id INT AUTO_INCREMENT NOT NULL, counter_id INT NOT NULL, uuid VARCHAR(36) NOT NULL, trade_order_uuid VARCHAR(36) NOT NULL, store_uuid VARCHAR(36) NOT NULL, counter_code_snapshot VARCHAR(50) NOT NULL, counter_name_snapshot VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_business_order_counter_pair (trade_order_uuid, counter_id), UNIQUE INDEX UNIQ_ORDCOUNTER_UUID (uuid), INDEX idx_business_order_counter_store (store_uuid), INDEX IDX_ORDCOUNTER_COUNTER (counter_id), PRIMARY KEY(id), CONSTRAINT FK_ORDCOUNTER_COUNTER FOREIGN KEY (counter_id) REFERENCES business_counter (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE business_order_counter');
        $this->addSql('DROP TABLE business_counter');
    }
}
