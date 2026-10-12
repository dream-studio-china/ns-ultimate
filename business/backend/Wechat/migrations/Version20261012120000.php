<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add WeChat Pay service-provider merchant configuration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wechat_merchant ADD payment_mode VARCHAR(32) DEFAULT 'direct' NOT NULL, ADD service_provider_app_id VARCHAR(128) DEFAULT NULL, ADD sub_merchant_id VARCHAR(64) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wechat_merchant DROP payment_mode, DROP service_provider_app_id, DROP sub_merchant_id');
    }
}
