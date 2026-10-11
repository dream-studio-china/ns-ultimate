<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create store-scoped WeChat configuration tables.';
    }

    public function up(Schema $schema): void
    {
        $application = $schema->createTable('wechat_application');
        $application->addColumn('id', 'integer', ['autoincrement' => true]);
        $application->addColumn('uuid', 'string', ['length' => 36]);
        $application->addColumn('name', 'string', ['length' => 120]);
        $application->addColumn('type', 'string', ['length' => 20]);
        $application->addColumn('app_id', 'string', ['length' => 64]);
        $application->addColumn('enabled', 'boolean', ['default' => true]);
        $application->addColumn('created_at', 'datetime_immutable');
        $application->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $application->setPrimaryKey(['id']);
        $application->addUniqueIndex(['uuid'], 'uniq_wechat_application_uuid');
        $application->addUniqueIndex(['app_id'], 'uniq_wechat_application_app_id');

        $applicationRevision = $schema->createTable('wechat_application_revision');
        $applicationRevision->addColumn('id', 'integer', ['autoincrement' => true]);
        $applicationRevision->addColumn('uuid', 'string', ['length' => 36]);
        $applicationRevision->addColumn('application_id', 'integer');
        $applicationRevision->addColumn('revision', 'integer');
        $applicationRevision->addColumn('secret_encrypted', 'text', ['notnull' => false]);
        $applicationRevision->addColumn('official_token_encrypted', 'text', ['notnull' => false]);
        $applicationRevision->addColumn('official_aes_key_encrypted', 'text', ['notnull' => false]);
        $applicationRevision->addColumn('key_id', 'string', ['length' => 32, 'notnull' => false]);
        $applicationRevision->addColumn('redirect_hosts', 'json', ['notnull' => false]);
        $applicationRevision->addColumn('state', 'string', ['length' => 20, 'default' => 'draft']);
        $applicationRevision->addColumn('created_at', 'datetime_immutable');
        $applicationRevision->addColumn('published_at', 'datetime_immutable', ['notnull' => false]);
        $applicationRevision->setPrimaryKey(['id']);
        $applicationRevision->addUniqueIndex(['uuid'], 'uniq_wechat_application_revision_uuid');
        $applicationRevision->addUniqueIndex(['application_id', 'revision'], 'uniq_wechat_application_revision_version');
        $applicationRevision->addIndex(['application_id', 'state'], 'idx_wechat_application_revision_state');
        $applicationRevision->addForeignKeyConstraint(
            'wechat_application',
            ['application_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_wechat_application_revision_application',
        );

        $merchant = $schema->createTable('wechat_merchant');
        $merchant->addColumn('id', 'integer', ['autoincrement' => true]);
        $merchant->addColumn('uuid', 'string', ['length' => 36]);
        $merchant->addColumn('name', 'string', ['length' => 120]);
        $merchant->addColumn('merchant_id', 'string', ['length' => 64]);
        $merchant->addColumn('mode', 'string', ['length' => 20, 'default' => 'direct']);
        $merchant->addColumn('enabled', 'boolean', ['default' => true]);
        $merchant->addColumn('created_at', 'datetime_immutable');
        $merchant->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $merchant->setPrimaryKey(['id']);
        $merchant->addUniqueIndex(['uuid'], 'uniq_wechat_merchant_uuid');
        $merchant->addUniqueIndex(['merchant_id'], 'uniq_wechat_merchant_merchant_id');

        $merchantRevision = $schema->createTable('wechat_merchant_revision');
        $merchantRevision->addColumn('id', 'integer', ['autoincrement' => true]);
        $merchantRevision->addColumn('uuid', 'string', ['length' => 36]);
        $merchantRevision->addColumn('merchant_profile_id', 'integer');
        $merchantRevision->addColumn('revision', 'integer');
        $merchantRevision->addColumn('mode', 'string', ['length' => 20, 'default' => 'direct']);
        $merchantRevision->addColumn('api_v3_key_encrypted', 'text', ['notnull' => false]);
        $merchantRevision->addColumn('private_key_encrypted', 'text', ['notnull' => false]);
        $merchantRevision->addColumn('certificate_encrypted', 'text', ['notnull' => false]);
        $merchantRevision->addColumn('certificate_serial', 'string', ['length' => 128, 'notnull' => false]);
        $merchantRevision->addColumn('platform_public_key_id', 'string', ['length' => 128, 'notnull' => false]);
        $merchantRevision->addColumn('platform_public_key_encrypted', 'text', ['notnull' => false]);
        $merchantRevision->addColumn('key_id', 'string', ['length' => 32, 'notnull' => false]);
        $merchantRevision->addColumn('state', 'string', ['length' => 20, 'default' => 'draft']);
        $merchantRevision->addColumn('created_at', 'datetime_immutable');
        $merchantRevision->addColumn('published_at', 'datetime_immutable', ['notnull' => false]);
        $merchantRevision->setPrimaryKey(['id']);
        $merchantRevision->addUniqueIndex(['uuid'], 'uniq_wechat_merchant_revision_uuid');
        $merchantRevision->addUniqueIndex(['merchant_profile_id', 'revision'], 'uniq_wechat_merchant_revision_version');
        $merchantRevision->addIndex(['merchant_profile_id', 'state'], 'idx_wechat_merchant_revision_state');
        $merchantRevision->addForeignKeyConstraint(
            'wechat_merchant',
            ['merchant_profile_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_wechat_merchant_revision_merchant',
        );

        $setting = $schema->createTable('store_wechat_setting');
        $setting->addColumn('id', 'integer', ['autoincrement' => true]);
        $setting->addColumn('uuid', 'string', ['length' => 36]);
        $setting->addColumn('store_uuid', 'string', ['length' => 36]);
        $setting->addColumn('enabled', 'boolean', ['default' => true]);
        $setting->addColumn('created_at', 'datetime_immutable');
        $setting->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $setting->setPrimaryKey(['id']);
        $setting->addUniqueIndex(['uuid'], 'uniq_store_wechat_setting_uuid');
        $setting->addUniqueIndex(['store_uuid'], 'uniq_store_wechat_setting_store_uuid');

        $settingRevision = $schema->createTable('store_wechat_setting_revision');
        $settingRevision->addColumn('id', 'integer', ['autoincrement' => true]);
        $settingRevision->addColumn('uuid', 'string', ['length' => 36]);
        $settingRevision->addColumn('setting_id', 'integer');
        $settingRevision->addColumn('revision', 'integer');
        $settingRevision->addColumn('miniapp_revision_id', 'integer', ['notnull' => false]);
        $settingRevision->addColumn('official_revision_id', 'integer', ['notnull' => false]);
        $settingRevision->addColumn('merchant_revision_id', 'integer', ['notnull' => false]);
        $settingRevision->addColumn('capabilities', 'json');
        $settingRevision->addColumn('state', 'string', ['length' => 20, 'default' => 'draft']);
        $settingRevision->addColumn('created_at', 'datetime_immutable');
        $settingRevision->addColumn('published_at', 'datetime_immutable', ['notnull' => false]);
        $settingRevision->setPrimaryKey(['id']);
        $settingRevision->addUniqueIndex(['uuid'], 'uniq_store_wechat_setting_revision_uuid');
        $settingRevision->addUniqueIndex(['setting_id', 'revision'], 'uniq_store_wechat_setting_revision_version');
        $settingRevision->addIndex(['setting_id', 'state'], 'idx_store_wechat_setting_revision_state');
        $settingRevision->addForeignKeyConstraint(
            'store_wechat_setting',
            ['setting_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_store_wechat_setting_revision_setting',
        );
        $settingRevision->addForeignKeyConstraint(
            'wechat_application_revision',
            ['miniapp_revision_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_store_wechat_setting_revision_miniapp',
        );
        $settingRevision->addForeignKeyConstraint(
            'wechat_application_revision',
            ['official_revision_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_store_wechat_setting_revision_official',
        );
        $settingRevision->addForeignKeyConstraint(
            'wechat_merchant_revision',
            ['merchant_revision_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_store_wechat_setting_revision_merchant',
        );
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'store_wechat_setting_revision',
            'store_wechat_setting',
            'wechat_merchant_revision',
            'wechat_merchant',
            'wechat_application_revision',
            'wechat_application',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
