<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250515000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create identity_refresh_token table for refresh token rotation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_refresh_token (
                id BIGINT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                refresh_token_hash VARCHAR(128) NOT NULL,
                jti VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                replaced_by_token_id BIGINT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                INDEX idx_refresh_token_hash (refresh_token_hash),
                INDEX idx_refresh_token_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_refresh_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_refresh_token');
    }
}
