<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow a WeChat service provider to have profiles for multiple sub-merchants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE wechat_merchant SET service_provider_app_id = COALESCE(service_provider_app_id, ''), sub_merchant_id = COALESCE(sub_merchant_id, '') WHERE service_provider_app_id IS NULL OR sub_merchant_id IS NULL");
        $this->addSql("ALTER TABLE wechat_merchant DROP INDEX uniq_wechat_merchant_mch_id, MODIFY service_provider_app_id VARCHAR(128) NOT NULL DEFAULT '', MODIFY sub_merchant_id VARCHAR(64) NOT NULL DEFAULT '', ADD UNIQUE INDEX uniq_wechat_merchant_profile (payment_mode, merchant_id, service_provider_app_id, sub_merchant_id)");
    }

    public function down(Schema $schema): void
    {
        $duplicateMerchantId = $this->connection->fetchOne('SELECT merchant_id FROM wechat_merchant GROUP BY merchant_id HAVING COUNT(*) > 1 LIMIT 1');
        $this->abortIf($duplicateMerchantId !== false, 'Cannot restore unique merchant IDs while multiple profiles use the same merchant ID.');

        $this->addSql('ALTER TABLE wechat_merchant DROP INDEX uniq_wechat_merchant_profile, MODIFY service_provider_app_id VARCHAR(128) DEFAULT NULL, MODIFY sub_merchant_id VARCHAR(64) DEFAULT NULL, ADD UNIQUE INDEX uniq_wechat_merchant_mch_id (merchant_id)');
    }
}
