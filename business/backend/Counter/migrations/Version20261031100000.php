<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add participant count, notes and revision to counter sessions.'; }

    public function isTransactional(): bool { return $this->connection->getDatabasePlatform() instanceof SQLitePlatform; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE counter_session ADD participant_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE counter_session ADD notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE counter_session ADD revision INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (['participant_count', 'notes', 'revision'] as $column) $this->addSql('ALTER TABLE counter_session DROP COLUMN '.$column);
    }
}
