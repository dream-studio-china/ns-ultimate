<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Store Product subtype discriminator and sort column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE trade_product ADD product_type VARCHAR(32) DEFAULT 'core' NOT NULL");
        $this->addSql('ALTER TABLE trade_product ADD sort INT DEFAULT 0');
        $this->addSql("UPDATE trade_product SET product_type = 'store', sort = 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_product DROP sort');
        $this->addSql('ALTER TABLE trade_product DROP product_type');
    }
}
