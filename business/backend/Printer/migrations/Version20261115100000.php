<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attach print jobs to their source order print rules for admin filtering.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE printer_job ADD rule_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_printer_job_rule_created ON printer_job (rule_uuid, created_at)');
        // Automatic and staff-manual order jobs use a deterministic key from their queued request.
        // Existing reprints have no source-request reference and remain unassigned.
        $this->addSql("UPDATE printer_job j INNER JOIN printer_order_request r ON j.idempotency_key = CONCAT('ord-', LEFT(SHA2(r.request_key, 256), 40)) SET j.rule_uuid = r.case_key");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_printer_job_rule_created ON printer_job');
        $this->addSql('ALTER TABLE printer_job DROP rule_uuid');
    }
}
