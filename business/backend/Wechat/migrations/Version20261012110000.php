<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow one user to be linked to multiple WeChat application identities.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_724F26ADA76ED395 ON store_wechat_identity (user_id)');
        $this->addSql('DROP INDEX uniq_store_wechat_identity_user ON store_wechat_identity');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne('SELECT user_id FROM store_wechat_identity GROUP BY user_id HAVING COUNT(*) > 1 LIMIT 1') !== false,
            'Cannot restore the unique user index while users have multiple WeChat identities.',
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_store_wechat_identity_user ON store_wechat_identity (user_id)');
        $this->addSql('DROP INDEX IDX_724F26ADA76ED395 ON store_wechat_identity');
    }
}
