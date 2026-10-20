<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Product recommendation, sold-out, and settings fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_product ADD recommendation_level INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE trade_product ADD is_sold_out BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE trade_product ADD settings JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_product DROP settings');
        $this->addSql('ALTER TABLE trade_product DROP is_sold_out');
        $this->addSql('ALTER TABLE trade_product DROP recommendation_level');
    }
}
