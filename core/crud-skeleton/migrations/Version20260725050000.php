<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend Trade order status for Store acceptance workflow states';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order MODIFY status VARCHAR(40) NOT NULL DEFAULT \'draft\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order MODIFY status VARCHAR(20) NOT NULL DEFAULT \'draft\'');
    }
}
