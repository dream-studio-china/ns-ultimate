<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align Store and Trade outbox schema with Doctrine mappings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_outbox_message CHANGE occurred_at occurred_at DATETIME NOT NULL, CHANGE available_at available_at DATETIME NOT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE store_outbox_message CHANGE occurred_at occurred_at DATETIME NOT NULL, CHANGE available_at available_at DATETIME NOT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE store CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE store_consumed_event CHANGE processed_at processed_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE store_order CHANGE accepted_at accepted_at DATETIME DEFAULT NULL, CHANGE rejected_at rejected_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE store_membership CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE store_membership RENAME INDEX idx_store_membership_store TO IDX_A8168968B092A811');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_membership RENAME INDEX IDX_A8168968B092A811 TO idx_store_membership_store');
    }
}
