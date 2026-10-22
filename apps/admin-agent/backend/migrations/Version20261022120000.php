<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add generic account-bound assistant proposals and execution receipts.';
    }

    public function up(Schema $schema): void
    {
        $target = new Schema();
        $table = $target->createTable('admin_agent_operation');
        $table->addColumn('id', 'string', ['length' => 64]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('actor', 'string', ['length' => 255]);
        $table->addColumn('action', 'string', ['length' => 16]);
        $table->addColumn('entity_key', 'string', ['length' => 120]);
        $table->addColumn('target_id', 'string', ['length' => 128, 'notnull' => false]);
        $table->addColumn('before_hash', 'string', ['length' => 64]);
        $table->addColumn('changes', 'json');
        $table->addColumn('fields', 'json');
        $table->addColumn('status', 'string', ['length' => 16]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('expires_at', 'datetime_immutable');
        $table->addColumn('completed_at', 'datetime_immutable', ['notnull' => false]);
        foreach ($target->toSql($this->connection->getDatabasePlatform()) as $sql) $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_agent_operation');
    }
}
