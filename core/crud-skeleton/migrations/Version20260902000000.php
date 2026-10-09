<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable Store to Product for shared/private catalog';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('trade_product');
        if (!$table->hasColumn('store_id')) {
            $table->addColumn('store_id', 'integer', ['notnull' => false]);
        }
        if (!$table->hasIndex('idx_trade_product_store')) {
            $table->addIndex(['store_id'], 'idx_trade_product_store');
        }
        if (!$table->hasForeignKey('fk_trade_product_store')) {
            $table->addForeignKeyConstraint('store', ['store_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_trade_product_store');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('trade_product');
        if ($table->hasForeignKey('fk_trade_product_store')) {
            $table->removeForeignKey('fk_trade_product_store');
        }
        if ($table->hasIndex('idx_trade_product_store')) {
            $table->dropIndex('idx_trade_product_store');
        }
        if ($table->hasColumn('store_id')) {
            $table->dropColumn('store_id');
        }
    }
}
