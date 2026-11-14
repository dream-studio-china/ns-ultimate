<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store the printer template item filter as a filter expression.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE printer_template MODIFY item_filter LONGTEXT DEFAULT NULL');
        // DEPRECATED: legacy all/kitchen tokens remain valid with their original
        // semantics. Never clear them: kitchen must not become an all-items filter.
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE printer_template SET item_filter = 'all' WHERE item_filter IS NULL");
        $this->addSql("ALTER TABLE printer_template MODIFY item_filter VARCHAR(20) NOT NULL DEFAULT 'all'");
    }
}
