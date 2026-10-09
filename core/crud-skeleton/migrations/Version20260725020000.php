<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Trade integration outbox table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE trade_outbox_message (
            id BIGINT AUTO_INCREMENT NOT NULL,
            event_id VARCHAR(36) NOT NULL,
            topic VARCHAR(120) NOT NULL,
            aggregate_type VARCHAR(80) NOT NULL,
            aggregate_id VARCHAR(64) NOT NULL,
            payload JSON NOT NULL,
            occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            attempts INT NOT NULL,
            last_error LONGTEXT DEFAULT NULL,
            UNIQUE INDEX uniq_trade_outbox_event_id (event_id),
            INDEX idx_trade_outbox_unpublished_available (published_at, available_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trade_outbox_message');
    }
}
