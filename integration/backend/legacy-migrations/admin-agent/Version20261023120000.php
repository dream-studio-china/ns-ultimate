<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account-bound create-only assistant batch proposals and execution receipts.';
    }

    public function up(Schema $schema): void
    {
        $target = new Schema();
        $table = $target->createTable('admin_agent_batch_operation');
        $table->addColumn('id', 'string', ['length' => 64]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('actor', 'string', ['length' => 255]);
        $table->addColumn('action', 'string', ['length' => 16]);
        $table->addColumn('entity_key', 'string', ['length' => 120]);
        $table->addColumn('row_count', 'integer');
        $table->addColumn('rows_hash', 'string', ['length' => 64]);
        $table->addColumn('proposal_rows', 'json');
        $table->addColumn('fields', 'json');
        $table->addColumn('status', 'string', ['length' => 16]);
        $table->addColumn('results', 'json');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('expires_at', 'datetime_immutable');
        $table->addColumn('completed_at', 'datetime_immutable', ['notnull' => false]);
        foreach ($target->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_agent_batch_operation');
    }
}
