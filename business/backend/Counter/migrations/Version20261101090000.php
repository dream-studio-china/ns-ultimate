<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Bind counter sessions to the dining mode and checkout rules used at order time.'; }

    public function isTransactional(): bool { return $this->connection->getDatabasePlatform() instanceof SQLitePlatform; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE counter_session ADD dining_mode_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE counter_session ADD dining_mode_snapshot JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (['dining_mode_snapshot', 'dining_mode_uuid'] as $column) $this->addSql('ALTER TABLE counter_session DROP COLUMN '.$column);
    }
}
