<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250517000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wallet and wallet_transaction tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'USD',
                balance BIGINT NOT NULL DEFAULT 0,
                version INT NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                label VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_wallet_user_currency (user_id, currency),
                INDEX idx_wallet_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE wallet
                ADD CONSTRAINT fk_wallet_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_transaction (
                id INT NOT NULL AUTO_INCREMENT,
                from_wallet_id INT DEFAULT NULL,
                to_wallet_id INT DEFAULT NULL,
                uuid VARCHAR(36) NOT NULL,
                amount BIGINT NOT NULL,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                reference_id VARCHAR(64) DEFAULT NULL,
                description LONGTEXT DEFAULT NULL,
                metadata LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_wallet_tx_uuid (uuid),
                UNIQUE INDEX uniq_wallet_tx_reference (reference_id),
                INDEX idx_wallet_tx_from (from_wallet_id),
                INDEX idx_wallet_tx_to (to_wallet_id),
                INDEX idx_wallet_tx_status (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE wallet_transaction
                ADD CONSTRAINT fk_wallet_tx_from FOREIGN KEY (from_wallet_id)
                    REFERENCES wallet (id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_wallet_tx_to FOREIGN KEY (to_wallet_id)
                    REFERENCES wallet (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wallet_transaction');
        $this->addSql('DROP TABLE wallet');
    }
}
