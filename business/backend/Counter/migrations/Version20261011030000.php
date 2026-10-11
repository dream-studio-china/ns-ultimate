<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sort and metadata to counters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE counter ADD sort INT DEFAULT 0 NOT NULL, ADD metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE counter DROP sort, DROP metadata');
    }
}
