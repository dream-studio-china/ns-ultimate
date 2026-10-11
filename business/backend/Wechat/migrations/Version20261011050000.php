<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create application-scoped WeChat identity and OAuth state tables.';
    }

    public function up(Schema $schema): void
    {
        $identity = $schema->createTable('wechat_identity');
        $identity->addColumn('id', 'integer', ['autoincrement' => true]);
        $identity->addColumn('uuid', 'string', ['length' => 36]);
        $identity->addColumn('user_id', 'integer');
        $identity->addColumn('application_type', 'string', ['length' => 20]);
        $identity->addColumn('app_id', 'string', ['length' => 64]);
        $identity->addColumn('openid', 'string', ['length' => 64]);
        $identity->addColumn('unionid', 'string', ['length' => 64, 'notnull' => false]);
        $identity->addColumn('session_key_encrypted', 'text', ['notnull' => false]);
        $identity->addColumn('session_key_key_id', 'string', ['length' => 32, 'notnull' => false]);
        $identity->addColumn('raw_data', 'json', ['notnull' => false]);
        $identity->addColumn('last_login_at', 'datetime_immutable');
        $identity->addColumn('created_at', 'datetime_immutable');
        $identity->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $identity->setPrimaryKey(['id']);
        $identity->addUniqueIndex(['uuid'], 'uniq_wechat_identity_uuid');
        $identity->addUniqueIndex(['app_id', 'openid'], 'uniq_wechat_identity_app_openid');
        $identity->addIndex(['user_id'], 'idx_wechat_identity_user');
        $identity->addIndex(['unionid'], 'idx_wechat_identity_unionid');
        $identity->addForeignKeyConstraint('user', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_wechat_identity_user');

        $state = $schema->createTable('wechat_oauth_state');
        $state->addColumn('id', 'integer', ['autoincrement' => true]);
        $state->addColumn('uuid', 'string', ['length' => 36]);
        $state->addColumn('nonce', 'string', ['length' => 64]);
        $state->addColumn('store_uuid', 'string', ['length' => 36]);
        $state->addColumn('application_revision_id', 'integer');
        $state->addColumn('redirect_host', 'string', ['length' => 255]);
        $state->addColumn('expires_at', 'datetime_immutable');
        $state->addColumn('consumed_at', 'datetime_immutable', ['notnull' => false]);
        $state->addColumn('created_at', 'datetime_immutable');
        $state->setPrimaryKey(['id']);
        $state->addUniqueIndex(['uuid'], 'uniq_wechat_oauth_state_uuid');
        $state->addUniqueIndex(['nonce'], 'uniq_wechat_oauth_state_nonce');
        $state->addForeignKeyConstraint(
            'wechat_application_revision',
            ['application_revision_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_wechat_oauth_state_revision',
        );
    }

    public function down(Schema $schema): void
    {
        foreach (['wechat_oauth_state', 'wechat_identity'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
