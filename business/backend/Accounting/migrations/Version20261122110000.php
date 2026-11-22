<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add external attachment references to accounting journal entries.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $column = new Column('attachments', Type::getType(Types::JSON), ['notnull' => false]);
        $this->addSql('ALTER TABLE ns_accounting_journal ADD COLUMN '.$this->platform->getColumnDeclarationSQL('attachments', $column->toArray()));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve accounting journal history.');
    }
}
