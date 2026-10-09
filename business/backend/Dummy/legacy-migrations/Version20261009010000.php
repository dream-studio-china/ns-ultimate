<?php

declare(strict_types=1);

namespace NsUltimate\Business\Note\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the legacy example table to business_dummy.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_note RENAME TO business_dummy');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_dummy RENAME TO business_note');
    }
}
