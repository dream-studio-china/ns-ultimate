<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wallet_voucher boundary ledger (deposit/withdraw vouchers)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_voucher (
                id INT AUTO_INCREMENT NOT NULL,
                uuid VARCHAR(36) NOT NULL,
                direction VARCHAR(10) NOT NULL,
                fund_source VARCHAR(10) NOT NULL,
                voucher_type VARCHAR(50) NOT NULL,
                voucher_id VARCHAR(64) NOT NULL,
                wallet_id INT NOT NULL,
                amount BIGINT NOT NULL,
                currency VARCHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                wallet_transaction_id VARCHAR(64) DEFAULT NULL,
                reversal_transaction_id VARCHAR(64) DEFAULT NULL,
                reference_id VARCHAR(64) NOT NULL,
                created_by VARCHAR(64) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                metadata JSON DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                applied_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                reversed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_wallet_voucher_uuid (uuid),
                UNIQUE INDEX uniq_wallet_voucher_reference (reference_id),
                UNIQUE INDEX uniq_wallet_voucher_source (voucher_type, voucher_id),
                INDEX idx_wallet_voucher_fund_status (fund_source, status),
                INDEX idx_wallet_voucher_currency_status (currency, status),
                INDEX IDX_WALLET_VOUCHER_WALLET (wallet_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE wallet_voucher ADD CONSTRAINT FK_WALLET_VOUCHER_WALLET FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallet_voucher DROP FOREIGN KEY FK_WALLET_VOUCHER_WALLET');
        $this->addSql('DROP TABLE wallet_voucher');
    }
}
