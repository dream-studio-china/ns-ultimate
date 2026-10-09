<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create base tables: users and content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id INT NOT NULL AUTO_INCREMENT,
                email VARCHAR(180) NOT NULL,
                username VARCHAR(180) NOT NULL,
                phone VARCHAR(20) DEFAULT NULL,
                phone_verified TINYINT(1) NOT NULL DEFAULT 0,
                password VARCHAR(255) NOT NULL,
                roles JSON NOT NULL,
                UNIQUE INDEX uniq_users_username (username),
                UNIQUE INDEX uniq_users_email (email),
                UNIQUE INDEX uniq_users_phone (phone),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE content (
                id INT NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                body LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content');
        $this->addSql('DROP TABLE users');
    }
}
