<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: add non-null created_at/updated_at with NOW() backfill and idx_users_created_at';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$schema->hasTable('users'), 'The users table is missing.');

        $table = $schema->getTable('users');

        // 1. 先以可空落地，避免历史行立即违反 NOT NULL
        if (!$table->hasColumn('created_at')) {
            $this->addSql("ALTER TABLE users ADD created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }
        if (!$table->hasColumn('updated_at')) {
            $this->addSql("ALTER TABLE users ADD updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }

        // 2. 自动回填历史数据为 NOW()
        $this->addSql('UPDATE users SET created_at = NOW(), updated_at = NOW() WHERE created_at IS NULL OR updated_at IS NULL');

        // 3. 收紧为非空
        $this->addSql("ALTER TABLE users MODIFY created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE users MODIFY updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");

        // 4. 同步加索引
        if (!$table->hasIndex('idx_users_created_at')) {
            $this->addSql('CREATE INDEX idx_users_created_at ON users (created_at)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$schema->hasTable('users'), 'The users table is missing.');

        $table = $schema->getTable('users');
        if ($table->hasIndex('idx_users_created_at')) {
            $this->addSql('DROP INDEX idx_users_created_at ON users');
        }
        if ($table->hasColumn('updated_at')) {
            $this->addSql('ALTER TABLE users DROP COLUMN updated_at');
        }
        if ($table->hasColumn('created_at')) {
            $this->addSql('ALTER TABLE users DROP COLUMN created_at');
        }
    }
}
