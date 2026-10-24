<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove retired admin-agent proposal and receipt tables, including all historical payloads.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS admin_agent_batch_operation');
        $this->addSql('DROP TABLE IF EXISTS admin_agent_operation');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Retired assistant proposals and receipts cannot be recovered. Restore a database backup if needed.');
    }
}
