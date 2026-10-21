<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider identity to printer accounts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE printer_account ADD provider VARCHAR(32) DEFAULT 'xpyun' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE printer_account DROP provider');
    }
}
