<?php

declare(strict_types=1);

namespace NsUltimate\Business\Note\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the original example table (legacy Note migration identity).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('business_note');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('title', 'string', ['length' => 160]);
        $table->addColumn('body', 'text');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('business_note');
    }
}
