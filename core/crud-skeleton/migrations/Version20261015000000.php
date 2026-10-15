<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support multiple AppID-scoped WeChat identities per Core user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wechat_user ADD app_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE wechat_user MODIFY openid VARCHAR(128) NOT NULL');

        // Keep an index available for the user_id foreign key before dropping
        // its existing unique index (required by MySQL).
        $this->addSql('CREATE INDEX idx_wechat_user_user ON wechat_user (user_id)');

        $isMysql = str_contains($this->connection->getDatabasePlatform()::class, 'MySQL');
        $this->addSql($isMysql
            ? 'DROP INDEX uniq_wechat_user_openid ON wechat_user'
            : 'DROP INDEX uniq_wechat_user_openid');
        $this->addSql($isMysql
            ? 'DROP INDEX uniq_wechat_user_user ON wechat_user'
            : 'DROP INDEX uniq_wechat_user_user');
        $this->addSql('CREATE UNIQUE INDEX uniq_wechat_user_app_openid ON wechat_user (app_id, openid)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne('SELECT id FROM wechat_user WHERE app_id IS NOT NULL LIMIT 1') !== false,
            'Cannot remove AppID-scoped WeChat identities after any identity has been assigned an AppID.',
        );
        $this->abortIf(
            $this->connection->fetchOne('SELECT user_id FROM wechat_user GROUP BY user_id HAVING COUNT(*) > 1 LIMIT 1') !== false,
            'Cannot restore one-WechatUser-per-user while users have multiple identities.',
        );
        $this->abortIf(
            $this->connection->fetchOne('SELECT openid FROM wechat_user GROUP BY openid HAVING COUNT(*) > 1 LIMIT 1') !== false,
            'Cannot restore globally unique OpenIDs while duplicate OpenIDs exist.',
        );

        $isMysql = str_contains($this->connection->getDatabasePlatform()::class, 'MySQL');
        // Restore the foreign-key replacement index before dropping the
        // non-unique index currently supporting user_id.
        $this->addSql('CREATE UNIQUE INDEX uniq_wechat_user_user ON wechat_user (user_id)');
        $this->addSql($isMysql
            ? 'DROP INDEX idx_wechat_user_user ON wechat_user'
            : 'DROP INDEX idx_wechat_user_user');
        $this->addSql('CREATE UNIQUE INDEX uniq_wechat_user_openid ON wechat_user (openid)');
        $this->addSql($isMysql
            ? 'DROP INDEX uniq_wechat_user_app_openid ON wechat_user'
            : 'DROP INDEX uniq_wechat_user_app_openid');
        $this->addSql('ALTER TABLE wechat_user DROP app_id');
        $this->addSql('ALTER TABLE wechat_user MODIFY openid VARCHAR(64) NOT NULL');
    }
}
