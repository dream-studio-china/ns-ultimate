<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Inventory materials, stock, recipes, reservations, ledger, inbox, and outbox tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE inventory_material (
            id INT AUTO_INCREMENT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            unit VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            metadata JSON DEFAULT NULL,
            stock_mutated TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_inventory_material_uuid (uuid),
            UNIQUE INDEX uniq_inventory_material_code (code),
            INDEX idx_inventory_material_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE inventory_stock (
            id INT AUTO_INCREMENT NOT NULL,
            material_id INT NOT NULL,
            store_uuid VARCHAR(36) NOT NULL,
            on_hand_quantity NUMERIC(20, 6) NOT NULL,
            reserved_quantity NUMERIC(20, 6) NOT NULL,
            allow_negative_stock TINYINT(1) NOT NULL,
            version INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_inventory_stock_store_material (store_uuid, material_id),
            INDEX idx_inventory_stock_store_updated (store_uuid, updated_at),
            INDEX IDX_INVENTORY_STOCK_MATERIAL (material_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE inventory_stock ADD CONSTRAINT FK_INVENTORY_STOCK_MATERIAL FOREIGN KEY (material_id) REFERENCES inventory_material (id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE inventory_specification_recipe (
            id INT AUTO_INCREMENT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            specification_uuid VARCHAR(36) NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_inventory_recipe_uuid (uuid),
            UNIQUE INDEX uniq_inventory_recipe_specification (specification_uuid),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE inventory_recipe_line (
            id INT AUTO_INCREMENT NOT NULL,
            recipe_id INT NOT NULL,
            material_id INT NOT NULL,
            quantity_per_unit NUMERIC(20, 6) NOT NULL,
            sort INT NOT NULL,
            UNIQUE INDEX uniq_inventory_recipe_line_material (recipe_id, material_id),
            INDEX IDX_INVENTORY_RECIPE_LINE_RECIPE (recipe_id),
            INDEX IDX_INVENTORY_RECIPE_LINE_MATERIAL (material_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE inventory_recipe_line ADD CONSTRAINT FK_INVENTORY_RECIPE_LINE_RECIPE FOREIGN KEY (recipe_id) REFERENCES inventory_specification_recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inventory_recipe_line ADD CONSTRAINT FK_INVENTORY_RECIPE_LINE_MATERIAL FOREIGN KEY (material_id) REFERENCES inventory_material (id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE inventory_reservation (
            id INT AUTO_INCREMENT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            reservation_id VARCHAR(36) NOT NULL,
            store_uuid VARCHAR(36) NOT NULL,
            trade_order_uuid VARCHAR(36) NOT NULL,
            store_order_uuid VARCHAR(36) NOT NULL,
            status VARCHAR(30) NOT NULL,
            request_hash VARCHAR(64) NOT NULL,
            rejection_code VARCHAR(50) DEFAULT NULL,
            rejection_reason LONGTEXT DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            released_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            consumed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_inventory_reservation_uuid (uuid),
            UNIQUE INDEX uniq_inventory_reservation_id (reservation_id),
            UNIQUE INDEX uniq_inventory_reservation_store_order (store_order_uuid),
            INDEX idx_inventory_reservation_store_status (store_uuid, status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE inventory_reservation_line (
            id INT AUTO_INCREMENT NOT NULL,
            reservation_id INT NOT NULL,
            material_uuid VARCHAR(36) NOT NULL,
            material_code_snapshot VARCHAR(64) NOT NULL,
            unit_snapshot VARCHAR(20) NOT NULL,
            requested_quantity NUMERIC(20, 6) NOT NULL,
            reserved_quantity NUMERIC(20, 6) NOT NULL,
            source_specification_uuids JSON NOT NULL,
            UNIQUE INDEX uniq_inventory_reservation_line_material (reservation_id, material_uuid),
            INDEX IDX_INVENTORY_RESERVATION_LINE_RESERVATION (reservation_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE inventory_reservation_line ADD CONSTRAINT FK_INVENTORY_RESERVATION_LINE_RESERVATION FOREIGN KEY (reservation_id) REFERENCES inventory_reservation (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE inventory_ledger_entry (
            id BIGINT AUTO_INCREMENT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            material_id INT NOT NULL,
            store_uuid VARCHAR(36) NOT NULL,
            type VARCHAR(20) NOT NULL,
            on_hand_delta NUMERIC(20, 6) NOT NULL,
            reserved_delta NUMERIC(20, 6) NOT NULL,
            on_hand_after NUMERIC(20, 6) NOT NULL,
            reserved_after NUMERIC(20, 6) NOT NULL,
            reference_type VARCHAR(80) NOT NULL,
            reference_id VARCHAR(64) NOT NULL,
            actor_reference VARCHAR(64) DEFAULT NULL,
            reason LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_inventory_ledger_uuid (uuid),
            UNIQUE INDEX uniq_inventory_ledger_operation (type, reference_id, store_uuid, material_id),
            INDEX idx_inventory_ledger_store_material_created (store_uuid, material_id, created_at),
            INDEX IDX_INVENTORY_LEDGER_MATERIAL (material_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE inventory_ledger_entry ADD CONSTRAINT FK_INVENTORY_LEDGER_MATERIAL FOREIGN KEY (material_id) REFERENCES inventory_material (id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE inventory_consumed_event (
            id BIGINT AUTO_INCREMENT NOT NULL,
            event_id VARCHAR(36) NOT NULL,
            topic VARCHAR(120) NOT NULL,
            aggregate_id VARCHAR(64) NOT NULL,
            processed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            payload_hash VARCHAR(64) NOT NULL,
            UNIQUE INDEX uniq_inventory_consumed_event_id (event_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE inventory_outbox_message (
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
            UNIQUE INDEX uniq_inventory_outbox_event_id (event_id),
            INDEX idx_inventory_outbox_unpublished_available (published_at, available_at, id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE store_trade_order_cancellation (
            id BIGINT AUTO_INCREMENT NOT NULL,
            trade_order_uuid VARCHAR(36) NOT NULL,
            store_uuid VARCHAR(36) NOT NULL,
            cancelled_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_store_trade_order_cancellation_trade_order (trade_order_uuid),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE store_trade_order_cancellation');
        $this->addSql('DROP TABLE inventory_outbox_message');
        $this->addSql('DROP TABLE inventory_consumed_event');
        $this->addSql('ALTER TABLE inventory_ledger_entry DROP FOREIGN KEY FK_INVENTORY_LEDGER_MATERIAL');
        $this->addSql('DROP TABLE inventory_ledger_entry');
        $this->addSql('ALTER TABLE inventory_reservation_line DROP FOREIGN KEY FK_INVENTORY_RESERVATION_LINE_RESERVATION');
        $this->addSql('DROP TABLE inventory_reservation_line');
        $this->addSql('DROP TABLE inventory_reservation');
        $this->addSql('ALTER TABLE inventory_recipe_line DROP FOREIGN KEY FK_INVENTORY_RECIPE_LINE_RECIPE');
        $this->addSql('ALTER TABLE inventory_recipe_line DROP FOREIGN KEY FK_INVENTORY_RECIPE_LINE_MATERIAL');
        $this->addSql('DROP TABLE inventory_recipe_line');
        $this->addSql('DROP TABLE inventory_specification_recipe');
        $this->addSql('ALTER TABLE inventory_stock DROP FOREIGN KEY FK_INVENTORY_STOCK_MATERIAL');
        $this->addSql('DROP TABLE inventory_stock');
        $this->addSql('DROP TABLE inventory_material');
    }
}
