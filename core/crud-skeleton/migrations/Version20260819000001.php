<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable source order-item audit fields to Settlement allocations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE settlement_allocation ADD source_item_id VARCHAR(64) DEFAULT NULL, ADD source_item_snapshot JSON DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_settlement_allocation_source_item ON settlement_allocation (source_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_settlement_allocation_source_item ON settlement_allocation');
        $this->addSql('ALTER TABLE settlement_allocation DROP source_item_id, DROP source_item_snapshot');
    }
}
