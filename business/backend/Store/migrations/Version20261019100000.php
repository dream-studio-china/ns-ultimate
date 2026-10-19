<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename Store Product sort to sort_order without changing existing values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_product RENAME COLUMN sort TO sort_order');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_product RENAME COLUMN sort_order TO sort');
    }
}
