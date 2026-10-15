<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the legacy store-scoped WeChat identity table after migration to Core WechatUser.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE store_wechat_identity');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'The legacy StoreWechatIdentity table cannot be restored after its data has been migrated to Core WechatUser.');
    }
}
