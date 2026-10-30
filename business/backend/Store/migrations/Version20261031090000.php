<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add store staff command receipts and store-owned catalog image references.'; }

    public function up(Schema $schema): void
    {
        $operations = $schema->createTable('store_staff_operation');
        foreach (['uuid', 'store_uuid', 'actor_uuid', 'command_uuid'] as $name) $operations->addColumn($name, 'string', ['length' => 36]);
        $operations->addColumn('action', 'string', ['length' => 80]);
        $operations->addColumn('fingerprint', 'string', ['length' => 64]);
        $operations->addColumn('before_state', 'json');
        $operations->addColumn('result', 'json');
        $operations->addColumn('created_at', 'datetime_immutable');
        $operations->setPrimaryKey(['uuid']);
        $operations->addUniqueIndex(['store_uuid', 'actor_uuid', 'command_uuid'], 'uniq_store_staff_command');
        $operations->addIndex(['store_uuid', 'created_at'], 'idx_store_staff_operation_created');
        $images = $schema->createTable('store_catalog_image');
        $images->addColumn('uuid', 'string', ['length' => 36]);
        $images->addColumn('store_uuid', 'string', ['length' => 36]);
        $images->addColumn('media_id', 'integer');
        $images->setPrimaryKey(['uuid']);
        $images->addForeignKeyConstraint('common_media', ['media_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('store_catalog_image');
        $schema->dropTable('store_staff_operation');
    }
}
