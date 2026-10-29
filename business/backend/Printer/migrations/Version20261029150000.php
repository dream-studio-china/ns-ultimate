<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Move ticket granularity and item selection onto printer templates.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE printer_template ADD ticket_mode VARCHAR(20) NOT NULL DEFAULT 'single', ADD item_filter VARCHAR(20) NOT NULL DEFAULT 'all'");
        $this->addSql('ALTER TABLE printer_order_rule DROP scene');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE printer_order_rule ADD scene VARCHAR(20) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE printer_template DROP ticket_mode, DROP item_filter');
    }
}
