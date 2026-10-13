<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the store WeChat Pay merchant profile optional.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_wechat_setting MODIFY merchant_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne('SELECT id FROM store_wechat_setting WHERE merchant_id IS NULL LIMIT 1') !== false,
            'Cannot require a merchant while store WeChat settings without merchants exist.',
        );
        $this->addSql('ALTER TABLE store_wechat_setting MODIFY merchant_id INT NOT NULL');
    }
}
