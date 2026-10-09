<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Settlement bundle tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE settlement_rule (
                id INT AUTO_INCREMENT NOT NULL,
                uuid VARCHAR(36) NOT NULL,
                code VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL,
                current_version INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_settlement_rule_uuid (uuid),
                UNIQUE INDEX uniq_settlement_rule_code (code),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE settlement_rule_version (
                id INT AUTO_INCREMENT NOT NULL,
                rule_uuid VARCHAR(36) NOT NULL,
                version INT NOT NULL,
                definition JSON NOT NULL,
                definition_hash VARCHAR(64) NOT NULL,
                effective_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                effective_to DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                priority INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                published_by VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                uuid VARCHAR(36) NOT NULL,
                UNIQUE INDEX uniq_settlement_rule_version_uuid (uuid),
                UNIQUE INDEX uniq_settlement_rule_version_rule_version (rule_uuid, version),
                INDEX idx_settlement_rule_version_active (status, effective_from, effective_to),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE settlement_plan (
                id INT AUTO_INCREMENT NOT NULL,
                funding_id VARCHAR(64) NOT NULL,
                source_type VARCHAR(64) NOT NULL,
                source_id VARCHAR(64) NOT NULL,
                funding_kind VARCHAR(50) DEFAULT 'confirmed' NOT NULL,
                confirmation_reference VARCHAR(128) NOT NULL,
                funding_fingerprint VARCHAR(64) NOT NULL,
                currency VARCHAR(32) NOT NULL,
                calculation_scale SMALLINT NOT NULL,
                funding_amount_quantum VARCHAR(128) NOT NULL,
                allocated_amount_quantum VARCHAR(128) NOT NULL,
                unallocated_amount_quantum VARCHAR(128) NOT NULL,
                posting_scale SMALLINT NOT NULL,
                funding_posting_amount VARCHAR(128) NOT NULL,
                allocated_posting_amount VARCHAR(128) NOT NULL,
                unallocated_posting_amount VARCHAR(128) NOT NULL,
                subject_type VARCHAR(80) NOT NULL,
                subject_id VARCHAR(64) NOT NULL,
                subject_version VARCHAR(40) NOT NULL,
                context_snapshot JSON NOT NULL,
                context_hash VARCHAR(64) NOT NULL,
                funding_snapshot JSON NOT NULL,
                rule_snapshot JSON NOT NULL,
                calculation_trace JSON NOT NULL,
                fallback_recipient_type VARCHAR(50) DEFAULT NULL,
                fallback_recipient_id VARCHAR(64) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                refund_locked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                refund_unlocked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                correlation_id VARCHAR(64) DEFAULT NULL,
                causation_id VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                uuid VARCHAR(36) NOT NULL,
                UNIQUE INDEX uniq_settlement_plan_uuid (uuid),
                UNIQUE INDEX uniq_settlement_plan_funding (funding_id),
                UNIQUE INDEX uniq_settlement_plan_source (source_type, source_id, funding_kind),
                INDEX idx_settlement_plan_status (status, created_at),
                INDEX idx_settlement_plan_source (source_type, source_id),
                INDEX idx_settlement_plan_refund_lock (refund_locked_at, refund_unlocked_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE settlement_allocation (
                id INT AUTO_INCREMENT NOT NULL,
                plan_id INT NOT NULL,
                plan_uuid VARCHAR(36) NOT NULL,
                sequence INT NOT NULL,
                allocation_key VARCHAR(128) NOT NULL,
                recipient_type VARCHAR(50) NOT NULL,
                recipient_id VARCHAR(64) NOT NULL,
                recipient_snapshot JSON NOT NULL,
                rule_code VARCHAR(100) DEFAULT NULL,
                rule_version_uuid VARCHAR(36) DEFAULT NULL,
                reason_code VARCHAR(100) NOT NULL,
                exact_amount_quantum VARCHAR(128) NOT NULL,
                posting_amount VARCHAR(128) NOT NULL,
                posting_scale SMALLINT NOT NULL,
                rounding_delta_quantum VARCHAR(128) NOT NULL,
                rounding_rank INT DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                posting_reference VARCHAR(128) DEFAULT NULL,
                posting_idempotency_key VARCHAR(128) NOT NULL,
                posted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                reversal_reference VARCHAR(128) DEFAULT NULL,
                reversal_idempotency_key VARCHAR(128) DEFAULT NULL,
                reversed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                failure_code VARCHAR(100) DEFAULT NULL,
                failure_detail LONGTEXT DEFAULT NULL,
                attempt_count INT DEFAULT 0 NOT NULL,
                next_attempt_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                uuid VARCHAR(36) NOT NULL,
                UNIQUE INDEX uniq_settlement_allocation_uuid (uuid),
                UNIQUE INDEX uniq_settlement_allocation_plan_key (plan_uuid, allocation_key),
                UNIQUE INDEX uniq_settlement_allocation_posting_key (posting_idempotency_key),
                UNIQUE INDEX uniq_settlement_allocation_reversal_key (reversal_idempotency_key),
                INDEX idx_settlement_allocation_plan_status (plan_uuid, status),
                INDEX idx_settlement_allocation_retry (status, next_attempt_at),
                INDEX IDX_FK_settlement_allocation_plan (plan_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_settlement_allocation_plan FOREIGN KEY (plan_id) REFERENCES settlement_plan (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE settlement_consumed_event (
                id INT AUTO_INCREMENT NOT NULL,
                event_id VARCHAR(64) NOT NULL,
                topic VARCHAR(120) NOT NULL,
                source_aggregate_type VARCHAR(80) NOT NULL,
                source_aggregate_id VARCHAR(64) NOT NULL,
                payload_hash VARCHAR(64) NOT NULL,
                processed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_settlement_consumed_event_id (event_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE settlement_outbox_message (
                id INT AUTO_INCREMENT NOT NULL,
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
                UNIQUE INDEX uniq_settlement_outbox_event_id (event_id),
                INDEX idx_settlement_outbox_unpublished_available (published_at, available_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE settlement_allocation DROP FOREIGN KEY FK_settlement_allocation_plan');
        $this->addSql('DROP TABLE settlement_allocation');
        $this->addSql('DROP TABLE settlement_consumed_event');
        $this->addSql('DROP TABLE settlement_outbox_message');
        $this->addSql('DROP TABLE settlement_plan');
        $this->addSql('DROP TABLE settlement_rule_version');
        $this->addSql('DROP TABLE settlement_rule');
    }
}
