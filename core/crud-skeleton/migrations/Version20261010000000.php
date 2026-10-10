<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add store-scoped product categories and optional product category association.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE store_product_category (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, store_id INT DEFAULT NULL, scope_key VARCHAR(45) NOT NULL, parent_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, sort_order INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_store_product_category_uuid (uuid), UNIQUE INDEX uniq_store_product_category_scope_slug (scope_key, slug), INDEX idx_store_product_category_store_enabled (store_id, enabled), INDEX IDX_58C2A5AA727ACA70 (parent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE store_product_category ADD CONSTRAINT FK_58C2A5AA727ACA70 FOREIGN KEY (parent_id) REFERENCES store_product_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE store_product_category ADD CONSTRAINT FK_58C2A5AA727ACA70B2D7 FOREIGN KEY (store_id) REFERENCES store (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trade_product ADD category_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_trade_product_category ON trade_product (category_id)');
        $this->addSql('ALTER TABLE trade_product ADD CONSTRAINT FK_6E7A3E5F12469DE2 FOREIGN KEY (category_id) REFERENCES store_product_category (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_product DROP FOREIGN KEY FK_6E7A3E5F12469DE2');
        $this->addSql('DROP INDEX idx_trade_product_category ON trade_product');
        $this->addSql('ALTER TABLE trade_product DROP category_id');
        $this->addSql('ALTER TABLE store_product_category DROP FOREIGN KEY FK_58C2A5AA727ACA70B2D7');
        $this->addSql('ALTER TABLE store_product_category DROP FOREIGN KEY FK_58C2A5AA727ACA70');
        $this->addSql('DROP TABLE store_product_category');
    }
}
