<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add simple WeChat application, merchant, and per-store selection records.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wechat_application (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(20) NOT NULL, app_id VARCHAR(128) NOT NULL, credentials_cipher LONGTEXT NOT NULL, UNIQUE INDEX uniq_wechat_application_uuid (uuid), UNIQUE INDEX uniq_wechat_application_app_id (app_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE wechat_merchant (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, name VARCHAR(120) NOT NULL, merchant_id VARCHAR(64) NOT NULL, credentials_cipher LONGTEXT NOT NULL, UNIQUE INDEX uniq_wechat_merchant_uuid (uuid), UNIQUE INDEX uniq_wechat_merchant_mch_id (merchant_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE store_wechat_identity (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, user_id INT NOT NULL, app_id VARCHAR(128) NOT NULL, openid VARCHAR(128) NOT NULL, app_type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_store_wechat_identity_app_openid (app_id, openid), UNIQUE INDEX uniq_store_wechat_identity_user (user_id), UNIQUE INDEX UNIQ_724F26ADD17F50A6 (uuid), PRIMARY KEY(id), CONSTRAINT FK_724F26ADA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE store_wechat_setting (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, store_uuid VARCHAR(36) NOT NULL, miniapp_id INT DEFAULT NULL, official_id INT DEFAULT NULL, merchant_id INT NOT NULL, UNIQUE INDEX UNIQ_F2FCA64FD17F50A6 (uuid), UNIQUE INDEX uniq_store_wechat_setting_store (store_uuid), INDEX IDX_F2FCA64F2459EB78 (miniapp_id), INDEX IDX_F2FCA64F4D88E615 (official_id), INDEX IDX_F2FCA64F6796D554 (merchant_id), PRIMARY KEY(id), CONSTRAINT FK_F2FCA64F2459EB78 FOREIGN KEY (miniapp_id) REFERENCES wechat_application (id) ON DELETE RESTRICT, CONSTRAINT FK_F2FCA64F4D88E615 FOREIGN KEY (official_id) REFERENCES wechat_application (id) ON DELETE RESTRICT, CONSTRAINT FK_F2FCA64F6796D554 FOREIGN KEY (merchant_id) REFERENCES wechat_merchant (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE store_wechat_setting');
        $this->addSql('DROP TABLE store_wechat_identity');
        $this->addSql('DROP TABLE wechat_merchant');
        $this->addSql('DROP TABLE wechat_application');
    }
}
