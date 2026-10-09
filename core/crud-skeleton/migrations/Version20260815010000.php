<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wallet.held column to support frozen/held balance separation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wallet ADD held BIGINT NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wallet DROP held");
    }
}
