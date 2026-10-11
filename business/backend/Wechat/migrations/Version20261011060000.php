<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add WeChat profile grants, audit events, and optimistic-lock columns.';
    }

    public function up(Schema $schema): void
    {
        $grant = $schema->createTable('wechat_profile_grant');
        $grant->addColumn('id', 'integer', ['autoincrement' => true]);
        $grant->addColumn('uuid', 'string', ['length' => 36]);
        $grant->addColumn('profile_type', 'string', ['length' => 20]);
        $grant->addColumn('profile_uuid', 'string', ['length' => 36]);
        $grant->addColumn('store_uuid', 'string', ['length' => 36]);
        $grant->addColumn('created_at', 'datetime_immutable');
        $grant->setPrimaryKey(['id']);
        $grant->addUniqueIndex(['uuid'], 'uniq_wechat_profile_grant_uuid');
        $grant->addUniqueIndex(['profile_type', 'profile_uuid', 'store_uuid'], 'uniq_wechat_profile_grant_scope');
        $grant->addIndex(['store_uuid'], 'idx_wechat_profile_grant_store');

        $audit = $schema->createTable('wechat_audit_event');
        $audit->addColumn('id', 'integer', ['autoincrement' => true]);
        $audit->addColumn('uuid', 'string', ['length' => 36]);
        $audit->addColumn('actor_user_id', 'integer', ['notnull' => false]);
        $audit->addColumn('store_uuid', 'string', ['length' => 36, 'notnull' => false]);
        $audit->addColumn('object_type', 'string', ['length' => 40]);
        $audit->addColumn('object_uuid', 'string', ['length' => 64]);
        $audit->addColumn('action', 'string', ['length' => 40]);
        $audit->addColumn('result', 'string', ['length' => 20, 'default' => 'success']);
        $audit->addColumn('request_id', 'string', ['length' => 64, 'notnull' => false]);
        $audit->addColumn('context', 'json', ['notnull' => false]);
        $audit->addColumn('created_at', 'datetime_immutable');
        $audit->setPrimaryKey(['id']);
        $audit->addUniqueIndex(['uuid'], 'uniq_wechat_audit_event_uuid');
        $audit->addIndex(['object_type', 'object_uuid'], 'idx_wechat_audit_event_object');
        $audit->addIndex(['store_uuid'], 'idx_wechat_audit_event_store');

        foreach (['wechat_application_revision', 'wechat_merchant_revision', 'store_wechat_setting'] as $tableName) {
            $table = $schema->getTable($tableName);
            $table->addColumn('lock_version', 'integer', ['default' => 1]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['wechat_application_revision', 'wechat_merchant_revision', 'store_wechat_setting'] as $tableName) {
            if ($schema->hasTable($tableName) && $schema->getTable($tableName)->hasColumn('lock_version')) {
                $schema->getTable($tableName)->dropColumn('lock_version');
            }
        }

        foreach (['wechat_audit_event', 'wechat_profile_grant'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
