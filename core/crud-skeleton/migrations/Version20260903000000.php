<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OrderItem: add specification_uuid column (step 1 of UUID migration)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$schema->hasTable('trade_order_item'),
            'The trade_order_item table is missing. Restore the Trade schema or correct the migration metadata before applying the OrderItem UUID migration.',
        );

        $table = $schema->getTable('trade_order_item');

        if (!$table->hasColumn('specification_uuid')) {
            $table->addColumn('specification_uuid', 'string', ['length' => 36, 'notnull' => false]);
        }
        if (!$table->hasIndex('idx_trade_order_item_spec_uuid')) {
            $table->addIndex(['specification_uuid'], 'idx_trade_order_item_spec_uuid');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$schema->hasTable('trade_order_item'),
            'The trade_order_item table is missing. Restore the Trade schema or correct the migration metadata before rolling back the OrderItem UUID migration.',
        );

        $table = $schema->getTable('trade_order_item');

        if ($table->hasIndex('idx_trade_order_item_spec_uuid')) {
            $table->dropIndex('idx_trade_order_item_spec_uuid');
        }
        if ($table->hasColumn('specification_uuid')) {
            $table->dropColumn('specification_uuid');
        }
    }
}
