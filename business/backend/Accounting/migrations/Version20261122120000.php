<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add independent metadata to accounting journal entries.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $column = new Column('metadata', Type::getType(Types::JSON_OBJECT), ['notnull' => false]);
        $this->addSql('ALTER TABLE ns_accounting_journal ADD COLUMN '.$this->platform->getColumnDeclarationSQL('metadata', $column->toArray()));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve accounting journal history.');
    }
}
