<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen wallet.currency to 32 chars to support unit-of-account codes (e.g. CNY.ESCROW)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wallet MODIFY currency VARCHAR(32) NOT NULL DEFAULT 'USD'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wallet MODIFY currency VARCHAR(10) NOT NULL DEFAULT 'USD'");
    }
}
