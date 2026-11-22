<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Create the store manual cash register without modifying the general ledger.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $addition = new Schema();
        $table = $addition->createTable('ns_store_cash_entry');
        foreach (['uuid' => 36, 'store_uuid' => 36, 'actor_uuid' => 36, 'currency' => 3, 'direction' => 8, 'category' => 24, 'note' => 500] as $name => $length) {
            $table->addColumn($name, 'string', ['length' => $length]);
        }
        $table->addColumn('entry_date', 'date_immutable');
        $table->addColumn('amount', 'bigint');
        $table->addColumn('metadata', 'json');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['uuid']);
        $table->addIndex(['store_uuid', 'entry_date', 'uuid'], 'idx_store_cash_month');
        foreach ($addition->toSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve store cash history.');
    }
}
