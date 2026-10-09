<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Store domain tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE store (
            id INT AUTO_INCREMENT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL,
            timezone VARCHAR(64) NOT NULL,
            contact JSON DEFAULT NULL,
            address JSON DEFAULT NULL,
            settings JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_store_uuid (uuid),
            UNIQUE INDEX uniq_store_code (code),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE store_membership (
            id INT AUTO_INCREMENT NOT NULL,
            store_id INT NOT NULL,
            user_uuid VARCHAR(36) NOT NULL,
            role VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_store_membership_store_user (store_id, user_uuid),
            INDEX idx_store_membership_user_status (user_uuid, status),
            INDEX IDX_STORE_MEMBERSHIP_STORE (store_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE store_membership ADD CONSTRAINT FK_STORE_MEMBERSHIP_STORE FOREIGN KEY (store_id) REFERENCES store (id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE store_order (
            id INT AUTO_INCREMENT NOT NULL,
            store_id INT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            trade_order_uuid VARCHAR(36) NOT NULL,
            store_code_snapshot VARCHAR(50) NOT NULL,
            store_name_snapshot VARCHAR(255) NOT NULL,
            customer_user_uuid VARCHAR(36) DEFAULT NULL,
            currency VARCHAR(10) NOT NULL,
            total_amount BIGINT NOT NULL,
            order_snapshot JSON NOT NULL,
            operational_status VARCHAR(40) NOT NULL,
            rejection_code VARCHAR(50) DEFAULT NULL,
            rejection_reason LONGTEXT DEFAULT NULL,
            accepted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            rejected_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            fulfillment_data JSON DEFAULT NULL,
            reservation_id VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_store_order_uuid (uuid),
            UNIQUE INDEX uniq_store_order_trade_order_uuid (trade_order_uuid),
            INDEX idx_store_order_store_status_created (store_id, operational_status, created_at),
            INDEX idx_store_order_customer_created (customer_user_uuid, created_at),
            INDEX idx_store_order_reservation_id (reservation_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE store_order ADD CONSTRAINT FK_STORE_ORDER_STORE FOREIGN KEY (store_id) REFERENCES store (id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE store_consumed_event (
            id BIGINT AUTO_INCREMENT NOT NULL,
            event_id VARCHAR(36) NOT NULL,
            topic VARCHAR(120) NOT NULL,
            aggregate_id VARCHAR(64) NOT NULL,
            processed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            payload_hash VARCHAR(64) NOT NULL,
            UNIQUE INDEX uniq_store_consumed_event_id (event_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE store_outbox_message (
            id BIGINT AUTO_INCREMENT NOT NULL,
            event_id VARCHAR(36) NOT NULL,
            topic VARCHAR(120) NOT NULL,
            aggregate_type VARCHAR(80) NOT NULL,
            aggregate_id VARCHAR(64) NOT NULL,
            payload JSON NOT NULL,
            occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            attempts INT NOT NULL,
            last_error LONGTEXT DEFAULT NULL,
            UNIQUE INDEX uniq_store_outbox_event_id (event_id),
            INDEX idx_store_outbox_unpublished_available (published_at, available_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE store_outbox_message');
        $this->addSql('DROP TABLE store_consumed_event');
        $this->addSql('ALTER TABLE store_order DROP FOREIGN KEY FK_STORE_ORDER_STORE');
        $this->addSql('DROP TABLE store_order');
        $this->addSql('ALTER TABLE store_membership DROP FOREIGN KEY FK_STORE_MEMBERSHIP_STORE');
        $this->addSql('DROP TABLE store_membership');
        $this->addSql('DROP TABLE store');
    }
}
