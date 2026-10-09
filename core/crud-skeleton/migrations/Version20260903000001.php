<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OrderItem: backfill specification_uuid and drop specification_id FK/column (step 2 of UUID migration)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$schema->hasTable('trade_order_item'),
            'The trade_order_item table is missing. Restore the Trade schema or correct the migration metadata before applying the OrderItem UUID migration.',
        );

        // Backfill UUID from existing FK. addSql runs BEFORE schema diff drop,
        // but specification_uuid already exists from Version20260903000000,
        // so the UPDATE is safe and executes before the FK/column are dropped.
        $this->addSql('UPDATE trade_order_item oi SET specification_uuid = (SELECT s.uuid FROM trade_specification s WHERE s.id = oi.specification_id) WHERE oi.specification_id IS NOT NULL AND oi.specification_uuid IS NULL');

        $table = $schema->getTable('trade_order_item');

        // MySQL requires the FK to be removed before its supporting index.
        // Explicit SQL preserves that order; Doctrine's schema diff may not.
        if ($table->hasForeignKey('fk_trade_order_item_specification')) {
            $this->addSql('ALTER TABLE trade_order_item DROP FOREIGN KEY fk_trade_order_item_specification');
        } elseif ($table->hasForeignKey('FK_TRADE_ORDER_ITEM_SPECIFICATION')) {
            $this->addSql('ALTER TABLE trade_order_item DROP FOREIGN KEY FK_TRADE_ORDER_ITEM_SPECIFICATION');
        }
        if ($table->hasIndex('idx_trade_order_item_specification')) {
            $this->addSql('ALTER TABLE trade_order_item DROP INDEX idx_trade_order_item_specification');
        }
        if ($table->hasIndex('IDX_TRADE_ORDER_ITEM_SPECIFICATION')) {
            $this->addSql('ALTER TABLE trade_order_item DROP INDEX IDX_TRADE_ORDER_ITEM_SPECIFICATION');
        }
        if ($table->hasIndex('IDX_7C7944E4908E2FFE')) {
            $this->addSql('ALTER TABLE trade_order_item DROP INDEX IDX_7C7944E4908E2FFE');
        }
        if ($table->hasColumn('specification_id')) {
            $this->addSql('ALTER TABLE trade_order_item DROP COLUMN specification_id');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'OrderItem UUID reference migration cannot be rolled back without losing catalog references.');
    }
}
