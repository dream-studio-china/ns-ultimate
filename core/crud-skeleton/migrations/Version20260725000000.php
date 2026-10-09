<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UUID external identity to users';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');

        if (!$users->hasColumn('uuid')) {
            $this->addSql('ALTER TABLE users ADD uuid VARCHAR(36) DEFAULT NULL AFTER id');
        }

        $this->addSql('UPDATE users SET uuid = UUID() WHERE uuid IS NULL');
        $this->addSql('UPDATE users u INNER JOIN (SELECT uuid FROM (SELECT uuid FROM users GROUP BY uuid HAVING COUNT(*) > 1) duplicate_source) duplicates ON u.uuid = duplicates.uuid SET u.uuid = UUID()');
        $this->addSql('ALTER TABLE users MODIFY uuid VARCHAR(36) NOT NULL');

        if (!$users->hasIndex('uniq_users_uuid')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_users_uuid ON users (uuid)');
        }
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');

        if ($users->hasIndex('uniq_users_uuid')) {
            $this->addSql('DROP INDEX uniq_users_uuid ON users');
        }

        if ($users->hasColumn('uuid')) {
            $this->addSql('ALTER TABLE users DROP uuid');
        }
    }
}
