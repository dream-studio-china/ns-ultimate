<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250620000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trade tables: product, specification, order, order_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trade_product (
                id INT NOT NULL AUTO_INCREMENT,
                uuid VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'active' NOT NULL,
                is_deleted TINYINT(1) DEFAULT 0 NOT NULL,
                metadata JSON DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_trade_product_uuid (uuid),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE trade_specification (
                id INT NOT NULL AUTO_INCREMENT,
                product_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                price BIGINT DEFAULT 0 NOT NULL,
                status VARCHAR(20) DEFAULT 'active' NOT NULL,
                sort INT DEFAULT 0 NOT NULL,
                is_deleted TINYINT(1) DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_TRADE_SPECIFICATION_PRODUCT (product_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_TRADE_SPECIFICATION_PRODUCT FOREIGN KEY (product_id) REFERENCES trade_product (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE trade_order (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT DEFAULT NULL,
                uuid VARCHAR(36) NOT NULL,
                total_amount BIGINT DEFAULT 0 NOT NULL,
                currency VARCHAR(10) DEFAULT 'CNY' NOT NULL,
                status VARCHAR(20) DEFAULT 'draft' NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                metadata JSON DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_trade_order_uuid (uuid),
                INDEX IDX_TRADE_ORDER_USER (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_TRADE_ORDER_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE trade_order_item (
                id INT NOT NULL AUTO_INCREMENT,
                order_id INT NOT NULL,
                specification_id INT DEFAULT NULL,
                uuid VARCHAR(36) NOT NULL,
                specification_title VARCHAR(255) DEFAULT NULL,
                quantity INT DEFAULT 1 NOT NULL,
                unit_price BIGINT DEFAULT 0 NOT NULL,
                price BIGINT DEFAULT 0 NOT NULL,
                cost BIGINT DEFAULT 0 NOT NULL,
                profit BIGINT DEFAULT 0 NOT NULL,
                spec_snapshot JSON DEFAULT NULL,
                product_snapshot JSON DEFAULT NULL,
                metadata JSON DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_trade_order_item_uuid (uuid),
                INDEX IDX_TRADE_ORDER_ITEM_ORDER (order_id),
                INDEX IDX_TRADE_ORDER_ITEM_SPECIFICATION (specification_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_TRADE_ORDER_ITEM_ORDER FOREIGN KEY (order_id) REFERENCES trade_order (id) ON DELETE CASCADE,
                CONSTRAINT FK_TRADE_ORDER_ITEM_SPECIFICATION FOREIGN KEY (specification_id) REFERENCES trade_specification (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order_item DROP FOREIGN KEY FK_TRADE_ORDER_ITEM_SPECIFICATION');
        $this->addSql('ALTER TABLE trade_specification DROP FOREIGN KEY FK_TRADE_SPECIFICATION_PRODUCT');
        $this->addSql('ALTER TABLE trade_order DROP FOREIGN KEY FK_TRADE_ORDER_USER');
        $this->addSql('ALTER TABLE trade_order_item DROP FOREIGN KEY FK_TRADE_ORDER_ITEM_ORDER');
        $this->addSql('DROP TABLE trade_order_item');
        $this->addSql('DROP TABLE trade_order');
        $this->addSql('DROP TABLE trade_specification');
        $this->addSql('DROP TABLE trade_product');
    }
}
