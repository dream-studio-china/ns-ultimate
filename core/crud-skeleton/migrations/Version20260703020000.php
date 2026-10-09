<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create identity_profile table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_profile (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            level VARCHAR(30) DEFAULT \'bronze\' NOT NULL,
            nickname VARCHAR(255) DEFAULT NULL,
            avatar VARCHAR(500) DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            joined_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_identity_profile_uuid (uuid),
            UNIQUE INDEX UNIQ_IDENTITY_PROFILE_USER (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE identity_profile ADD CONSTRAINT FK_IDENTITY_PROFILE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_profile DROP FOREIGN KEY FK_IDENTITY_PROFILE_USER');
        $this->addSql('DROP TABLE identity_profile');
    }
}
