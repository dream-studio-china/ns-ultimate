<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add metadata to common_content for Authorization field-grant pilot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_content ADD metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_content DROP metadata');
    }
}
