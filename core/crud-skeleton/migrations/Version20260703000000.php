<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add storage driver and nullable user owner fields to common media';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE common_media ADD storage VARCHAR(20) NOT NULL DEFAULT 'local'");
        $this->addSql('ALTER TABLE common_media ADD user_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_common_media_user ON common_media (user_id)');
        $this->addSql('ALTER TABLE common_media ADD CONSTRAINT fk_common_media_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_media DROP FOREIGN KEY fk_common_media_user');
        $this->addSql('DROP INDEX idx_common_media_user ON common_media');
        $this->addSql('ALTER TABLE common_media DROP user_id');
        $this->addSql('ALTER TABLE common_media DROP storage');
    }
}
