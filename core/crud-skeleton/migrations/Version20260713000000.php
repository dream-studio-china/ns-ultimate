<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create common_picture table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE common_picture (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT DEFAULT NULL,
            category_id INT NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            image VARCHAR(1024) NOT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_COMMON_PICTURE_USER (user_id),
            INDEX IDX_COMMON_PICTURE_CATEGORY (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE common_picture ADD CONSTRAINT FK_COMMON_PICTURE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE common_picture ADD CONSTRAINT FK_COMMON_PICTURE_CATEGORY FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_picture DROP FOREIGN KEY FK_COMMON_PICTURE_USER');
        $this->addSql('ALTER TABLE common_picture DROP FOREIGN KEY FK_COMMON_PICTURE_CATEGORY');
        $this->addSql('DROP TABLE common_picture');
    }
}
