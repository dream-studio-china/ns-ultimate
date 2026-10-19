<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the Common Content subtype discriminator and sort_order field.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE common_content ADD content_type VARCHAR(32) DEFAULT 'core' NOT NULL");
        $this->addSql('ALTER TABLE common_content ADD sort_order INT DEFAULT 0');
        $this->addSql("UPDATE common_content SET content_type = 'common', sort_order = 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_content DROP sort_order');
        $this->addSql('ALTER TABLE common_content DROP content_type');
    }
}
