<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wallet_voucher_comment append-only annotation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_voucher_comment (
                id INT AUTO_INCREMENT NOT NULL,
                voucher_id INT NOT NULL,
                actor VARCHAR(64) NOT NULL,
                text VARCHAR(1000) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_WALLET_VOUCHER_COMMENT_VOUCHER (voucher_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE wallet_voucher_comment ADD CONSTRAINT FK_WALLET_VOUCHER_COMMENT_VOUCHER FOREIGN KEY (voucher_id) REFERENCES wallet_voucher (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallet_voucher_comment DROP FOREIGN KEY FK_WALLET_VOUCHER_COMMENT_VOUCHER');
        $this->addSql('DROP TABLE wallet_voucher_comment');
    }
}
