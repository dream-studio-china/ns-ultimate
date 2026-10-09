<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable category relation to common media';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_media ADD category_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_ED949AC612469DE2 ON common_media (category_id)');
        $this->addSql('ALTER TABLE common_media ADD CONSTRAINT FK_ED949AC612469DE2 FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_media DROP FOREIGN KEY FK_ED949AC612469DE2');
        $this->addSql('DROP INDEX IDX_ED949AC612469DE2 ON common_media');
        $this->addSql('ALTER TABLE common_media DROP category_id');
    }
}
