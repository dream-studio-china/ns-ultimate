<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Authorization module tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE authorization_permission (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(120) NOT NULL, module VARCHAR(60) NOT NULL, resource VARCHAR(60) NOT NULL, action VARCHAR(60) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, is_system TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_authorization_permission_code (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE authorization_role (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(120) NOT NULL, scope_type VARCHAR(20) NOT NULL, is_system TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_authorization_role_uuid (uuid), UNIQUE INDEX uniq_authorization_role_code (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE authorization_role_permission (role_id INT NOT NULL, permission_id INT NOT NULL, INDEX IDX_A9C0CC4AD60322AC (role_id), INDEX IDX_A9C0CC4AFED90CCA (permission_id), PRIMARY KEY(role_id, permission_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE authorization_role_permission ADD CONSTRAINT FK_A9C0CC4AD60322AC FOREIGN KEY (role_id) REFERENCES authorization_role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE authorization_role_permission ADD CONSTRAINT FK_A9C0CC4AFED90CCA FOREIGN KEY (permission_id) REFERENCES authorization_permission (id) ON DELETE RESTRICT');
        $this->addSql('CREATE TABLE authorization_assignment (id INT AUTO_INCREMENT NOT NULL, role_id INT NOT NULL, user_uuid VARCHAR(36) NOT NULL, scope_type VARCHAR(20) NOT NULL, scope_uuid VARCHAR(36) DEFAULT NULL, scope_key VARCHAR(36) NOT NULL, granted_by_uuid VARCHAR(36) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', uuid VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_authorization_assignment_uuid (uuid), UNIQUE INDEX uniq_authorization_assignment (user_uuid, role_id, scope_type, scope_key), INDEX idx_authorization_assignment_user_revoked (user_uuid, revoked_at), INDEX idx_authorization_assignment_scope_revoked (scope_type, scope_uuid, revoked_at), INDEX IDX_16C003C7D60322AC (role_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE authorization_assignment ADD CONSTRAINT FK_16C003C7D60322AC FOREIGN KEY (role_id) REFERENCES authorization_role (id) ON DELETE RESTRICT');
        $this->addSql('CREATE TABLE authorization_role_field_grant (id INT AUTO_INCREMENT NOT NULL, role_id INT NOT NULL, resource VARCHAR(80) NOT NULL, action VARCHAR(60) NOT NULL, fields JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_authorization_role_field_grant (role_id, resource, action), INDEX IDX_94D610D0D60322AC (role_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE authorization_role_field_grant ADD CONSTRAINT FK_94D610D0D60322AC FOREIGN KEY (role_id) REFERENCES authorization_role (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE authorization_audit_log (id BIGINT AUTO_INCREMENT NOT NULL, actor_uuid VARCHAR(36) DEFAULT NULL, action VARCHAR(120) NOT NULL, target_type VARCHAR(80) NOT NULL, target_uuid VARCHAR(36) DEFAULT NULL, before_data JSON DEFAULT NULL, after_data JSON DEFAULT NULL, request_id VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE authorization_role_permission DROP FOREIGN KEY FK_A9C0CC4AD60322AC');
        $this->addSql('ALTER TABLE authorization_role_permission DROP FOREIGN KEY FK_A9C0CC4AFED90CCA');
        $this->addSql('ALTER TABLE authorization_assignment DROP FOREIGN KEY FK_16C003C7D60322AC');
        $this->addSql('ALTER TABLE authorization_role_field_grant DROP FOREIGN KEY FK_94D610D0D60322AC');
        $this->addSql('DROP TABLE authorization_permission');
        $this->addSql('DROP TABLE authorization_role');
        $this->addSql('DROP TABLE authorization_role_permission');
        $this->addSql('DROP TABLE authorization_assignment');
        $this->addSql('DROP TABLE authorization_role_field_grant');
        $this->addSql('DROP TABLE authorization_audit_log');
    }
}
