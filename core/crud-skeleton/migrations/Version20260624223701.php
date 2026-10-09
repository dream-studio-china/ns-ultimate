<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624223701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payment_invoice (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, out_trade_no VARCHAR(64) NOT NULL, transaction_id VARCHAR(128) DEFAULT NULL, source_type VARCHAR(50) NOT NULL, source_id VARCHAR(64) NOT NULL, scene VARCHAR(50) NOT NULL, payment VARCHAR(50) DEFAULT NULL, gateway VARCHAR(50) DEFAULT NULL, trade_type VARCHAR(50) DEFAULT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, amount BIGINT DEFAULT 0 NOT NULL, refunded_amount BIGINT DEFAULT 0 NOT NULL, currency VARCHAR(10) DEFAULT \'CNY\' NOT NULL, subject VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, extra_data JSON DEFAULT NULL, created_at DATETIME NOT NULL, paid_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, refunded_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, payer_id INT DEFAULT NULL, INDEX IDX_892C19AEC17AD9A9 (payer_id), INDEX idx_payment_invoice_source_status (source_type, source_id, status), INDEX idx_payment_invoice_source_scene (source_type, source_id, scene), INDEX idx_payment_invoice_payment_transaction (payment, transaction_id), UNIQUE INDEX uniq_payment_invoice_uuid (uuid), UNIQUE INDEX uniq_payment_invoice_out_trade_no (out_trade_no), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wechat_user (id INT AUTO_INCREMENT NOT NULL, openid VARCHAR(64) NOT NULL, unionid VARCHAR(64) DEFAULT NULL, session_key VARCHAR(64) DEFAULT NULL, nickname VARCHAR(128) DEFAULT NULL, avatar VARCHAR(512) DEFAULT NULL, sex INT DEFAULT NULL, province VARCHAR(64) DEFAULT NULL, city VARCHAR(64) DEFAULT NULL, country VARCHAR(64) DEFAULT NULL, app_type VARCHAR(20) NOT NULL, raw_data JSON DEFAULT NULL, last_login_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_wechat_user_openid (openid), UNIQUE INDEX uniq_wechat_user_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payment_invoice ADD CONSTRAINT FK_892C19AEC17AD9A9 FOREIGN KEY (payer_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wechat_user ADD CONSTRAINT FK_4656660EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE common_category CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE common_category RENAME INDEX uniq_category_slug TO UNIQ_637CDE56989D9B62');
        $this->addSql('ALTER TABLE common_category RENAME INDEX idx_category_parent TO IDX_637CDE56727ACA70');
        $this->addSql('DROP INDEX idx_comment_entity ON common_comment');
        $this->addSql('ALTER TABLE common_comment CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE common_comment RENAME INDEX idx_comment_author TO IDX_146A0334F675F31B');
        $this->addSql('ALTER TABLE common_comment RENAME INDEX idx_comment_parent TO IDX_146A0334727ACA70');
        $this->addSql('ALTER TABLE common_content CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE common_content RENAME INDEX idx_common_content_category TO IDX_7EDB61F112469DE2');
        $this->addSql('ALTER TABLE common_content_tag RENAME INDEX idx_common_content_tag_content TO IDX_EC5C3E4384A0A3ED');
        $this->addSql('ALTER TABLE common_content_tag RENAME INDEX idx_common_content_tag_tag TO IDX_EC5C3E43BAD26311');
        $this->addSql('ALTER TABLE common_media CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE common_page CHANGE published_at published_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE common_page RENAME INDEX uniq_page_slug TO UNIQ_A492AEB1989D9B62');
        $this->addSql('ALTER TABLE common_setting CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE common_setting RENAME INDEX uniq_setting_key TO UNIQ_1F6AE9C08A90ABA9');
        $this->addSql('ALTER TABLE common_tag CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE common_tag RENAME INDEX uniq_tag_slug TO UNIQ_4B0904F9989D9B62');
        $this->addSql('ALTER TABLE identity_refresh_token CHANGE created_at created_at DATETIME NOT NULL, CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE revoked_at revoked_at DATETIME DEFAULT NULL, CHANGE user_agent user_agent LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_order ADD invoice_id VARCHAR(64) DEFAULT NULL, ADD invoice_no VARCHAR(64) DEFAULT NULL, ADD payment_status VARCHAR(30) DEFAULT NULL, CHANGE cancelled_at cancelled_at DATETIME DEFAULT NULL, CHANGE completed_at completed_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE paid_at paid_at DATETIME DEFAULT NULL, CHANGE refunded_at refunded_at DATETIME DEFAULT NULL, CHANGE fulfilled_at fulfilled_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_order RENAME INDEX idx_trade_order_user TO IDX_DF24437BA76ED395');
        $this->addSql('ALTER TABLE trade_order_item CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE trade_order_item RENAME INDEX idx_trade_order_item_order TO IDX_7C7944E48D9F6D38');
        $this->addSql('ALTER TABLE trade_order_item RENAME INDEX idx_trade_order_item_specification TO IDX_7C7944E4908E2FFE');
        $this->addSql('ALTER TABLE trade_product CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_specification CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_specification RENAME INDEX idx_trade_specification_product TO IDX_9F4C1F264584665A');
        $this->addSql('ALTER TABLE wallet CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE wallet RENAME INDEX idx_wallet_user TO IDX_7C68921FA76ED395');
        $this->addSql('DROP INDEX idx_wallet_tx_status ON wallet_transaction');
        $this->addSql('ALTER TABLE wallet_transaction CHANGE created_at created_at DATETIME NOT NULL, CHANGE completed_at completed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX uniq_wallet_tx_uuid TO UNIQ_7DAF972D17F50A6');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX uniq_wallet_tx_reference TO UNIQ_7DAF9721645DEA9');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX idx_wallet_tx_from TO IDX_7DAF97261B9B549');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX idx_wallet_tx_to TO IDX_7DAF9724086F782');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment_invoice DROP FOREIGN KEY FK_892C19AEC17AD9A9');
        $this->addSql('ALTER TABLE wechat_user DROP FOREIGN KEY FK_4656660EA76ED395');
        $this->addSql('DROP TABLE payment_invoice');
        $this->addSql('DROP TABLE wechat_user');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE common_category CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE common_category RENAME INDEX uniq_637cde56989d9b62 TO uniq_category_slug');
        $this->addSql('ALTER TABLE common_category RENAME INDEX idx_637cde56727aca70 TO idx_category_parent');
        $this->addSql('ALTER TABLE common_comment CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_comment_entity ON common_comment (entity_type, entity_id)');
        $this->addSql('ALTER TABLE common_comment RENAME INDEX idx_146a0334f675f31b TO idx_comment_author');
        $this->addSql('ALTER TABLE common_comment RENAME INDEX idx_146a0334727aca70 TO idx_comment_parent');
        $this->addSql('ALTER TABLE common_content CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE common_content RENAME INDEX idx_7edb61f112469de2 TO idx_common_content_category');
        $this->addSql('ALTER TABLE common_content_tag RENAME INDEX idx_ec5c3e4384a0a3ed TO idx_common_content_tag_content');
        $this->addSql('ALTER TABLE common_content_tag RENAME INDEX idx_ec5c3e43bad26311 TO idx_common_content_tag_tag');
        $this->addSql('ALTER TABLE common_media CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE common_page CHANGE published_at published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE common_page RENAME INDEX uniq_a492aeb1989d9b62 TO uniq_page_slug');
        $this->addSql('ALTER TABLE common_setting CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE common_setting RENAME INDEX uniq_1f6ae9c08a90aba9 TO uniq_setting_key');
        $this->addSql('ALTER TABLE common_tag CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE common_tag RENAME INDEX uniq_4b0904f9989d9b62 TO uniq_tag_slug');
        $this->addSql('ALTER TABLE identity_refresh_token CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE expires_at expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE revoked_at revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE user_agent user_agent TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_order DROP invoice_id, DROP invoice_no, DROP payment_status, CHANGE cancelled_at cancelled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE completed_at completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE paid_at paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE refunded_at refunded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE fulfilled_at fulfilled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trade_order RENAME INDEX idx_df24437ba76ed395 TO IDX_TRADE_ORDER_USER');
        $this->addSql('ALTER TABLE trade_order_item CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trade_order_item RENAME INDEX idx_7c7944e48d9f6d38 TO IDX_TRADE_ORDER_ITEM_ORDER');
        $this->addSql('ALTER TABLE trade_order_item RENAME INDEX idx_7c7944e4908e2ffe TO IDX_TRADE_ORDER_ITEM_SPECIFICATION');
        $this->addSql('ALTER TABLE trade_product CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trade_specification CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trade_specification RENAME INDEX idx_9f4c1f264584665a TO IDX_TRADE_SPECIFICATION_PRODUCT');
        $this->addSql('ALTER TABLE wallet CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE wallet RENAME INDEX idx_7c68921fa76ed395 TO idx_wallet_user');
        $this->addSql('ALTER TABLE wallet_transaction CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE completed_at completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_wallet_tx_status ON wallet_transaction (status)');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX idx_7daf97261b9b549 TO idx_wallet_tx_from');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX idx_7daf9724086f782 TO idx_wallet_tx_to');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX uniq_7daf9721645dea9 TO uniq_wallet_tx_reference');
        $this->addSql('ALTER TABLE wallet_transaction RENAME INDEX uniq_7daf972d17f50a6 TO uniq_wallet_tx_uuid');
    }
}
