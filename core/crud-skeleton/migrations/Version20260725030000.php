<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UUID external identity to Trade specifications';
    }

    public function up(Schema $schema): void
    {
        $specifications = $schema->getTable('trade_specification');
        if (!$specifications->hasColumn('uuid')) {
            $this->addSql('ALTER TABLE trade_specification ADD uuid VARCHAR(36) DEFAULT NULL AFTER id');
        }
        $this->addSql('UPDATE trade_specification SET uuid = UUID() WHERE uuid IS NULL');
        $this->addSql('UPDATE trade_specification s INNER JOIN (SELECT uuid FROM (SELECT uuid FROM trade_specification GROUP BY uuid HAVING COUNT(*) > 1) duplicate_source) duplicates ON s.uuid = duplicates.uuid SET s.uuid = UUID()');
        $this->addSql('ALTER TABLE trade_specification MODIFY uuid VARCHAR(36) NOT NULL');
        if (!$specifications->hasIndex('uniq_trade_specification_uuid')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_trade_specification_uuid ON trade_specification (uuid)');
        }
    }

    public function down(Schema $schema): void
    {
        $specifications = $schema->getTable('trade_specification');
        if ($specifications->hasIndex('uniq_trade_specification_uuid')) {
            $this->addSql('DROP INDEX uniq_trade_specification_uuid ON trade_specification');
        }
        if ($specifications->hasColumn('uuid')) {
            $this->addSql('ALTER TABLE trade_specification DROP uuid');
        }
    }
}
