<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250516000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename content to common_content, create common modules: category, tag, content_tag, media, page, comment, setting';
    }

    public function up(Schema $schema): void
    {
        // Rename content table to common_content (matches new entity mapping)
        $this->addSql('RENAME TABLE content TO common_content');

        $this->addSql(<<<'SQL'
            CREATE TABLE common_category (
                id INT NOT NULL AUTO_INCREMENT,
                parent_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_category_slug (slug),
                INDEX idx_category_parent (parent_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE common_category
                ADD CONSTRAINT fk_category_parent FOREIGN KEY (parent_id)
                    REFERENCES common_category (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE common_tag (
                id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                color VARCHAR(7) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_tag_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE common_content_tag (
                content_id INT NOT NULL,
                tag_id INT NOT NULL,
                INDEX idx_common_content_tag_content (content_id),
                INDEX idx_common_content_tag_tag (tag_id),
                PRIMARY KEY(content_id, tag_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE common_content_tag
                ADD CONSTRAINT fk_common_content_tag_content FOREIGN KEY (content_id)
                    REFERENCES common_content (id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_common_content_tag_tag FOREIGN KEY (tag_id)
                    REFERENCES common_tag (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE common_media (
                id INT NOT NULL AUTO_INCREMENT,
                filename VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(255) NOT NULL,
                size BIGINT NOT NULL,
                path VARCHAR(1024) NOT NULL,
                alt VARCHAR(255) DEFAULT NULL,
                title VARCHAR(255) DEFAULT NULL,
                width INT DEFAULT NULL,
                height INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE common_page (
                id INT NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                body LONGTEXT DEFAULT NULL,
                meta_title VARCHAR(255) DEFAULT NULL,
                meta_description LONGTEXT DEFAULT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'draft',
                published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_page_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE common_comment (
                id INT NOT NULL AUTO_INCREMENT,
                author_id INT DEFAULT NULL,
                parent_id INT DEFAULT NULL,
                body LONGTEXT NOT NULL,
                author_name VARCHAR(255) DEFAULT NULL,
                author_email VARCHAR(255) DEFAULT NULL,
                entity_type VARCHAR(255) NOT NULL,
                entity_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_comment_entity (entity_type, entity_id),
                INDEX idx_comment_author (author_id),
                INDEX idx_comment_parent (parent_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE common_comment
                ADD CONSTRAINT fk_comment_author FOREIGN KEY (author_id)
                    REFERENCES users (id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_comment_parent FOREIGN KEY (parent_id)
                    REFERENCES common_comment (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE common_setting (
                id INT NOT NULL AUTO_INCREMENT,
                `key` VARCHAR(255) NOT NULL,
                value LONGTEXT DEFAULT NULL,
                type VARCHAR(50) NOT NULL DEFAULT 'string',
                group_name VARCHAR(255) DEFAULT NULL,
                label VARCHAR(255) DEFAULT NULL,
                description LONGTEXT DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_setting_key (`key`),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE common_content
                ADD category_id INT DEFAULT NULL,
                ADD INDEX idx_common_content_category (category_id),
                ADD CONSTRAINT fk_common_content_category FOREIGN KEY (category_id)
                    REFERENCES common_category (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_content DROP FOREIGN KEY fk_common_content_category');
        $this->addSql('ALTER TABLE common_content DROP INDEX idx_common_content_category');
        $this->addSql('ALTER TABLE common_content DROP category_id');
        $this->addSql('DROP TABLE common_content_tag');
        $this->addSql('DROP TABLE common_setting');
        $this->addSql('DROP TABLE common_comment');
        $this->addSql('DROP TABLE common_page');
        $this->addSql('DROP TABLE common_media');
        $this->addSql('DROP TABLE common_tag');
        $this->addSql('DROP TABLE common_category');
        $this->addSql('RENAME TABLE common_content TO content');
    }
}
