<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250621000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trade_order payment, fulfillment, and refund columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order ADD paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trade_order ADD refunded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trade_order ADD fulfilled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trade_order ADD payment_method VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_order ADD tracking_number VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_order ADD shipping_address LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_order ADD refund_reason LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order DROP paid_at');
        $this->addSql('ALTER TABLE trade_order DROP refunded_at');
        $this->addSql('ALTER TABLE trade_order DROP fulfilled_at');
        $this->addSql('ALTER TABLE trade_order DROP payment_method');
        $this->addSql('ALTER TABLE trade_order DROP tracking_number');
        $this->addSql('ALTER TABLE trade_order DROP shipping_address');
        $this->addSql('ALTER TABLE trade_order DROP refund_reason');
    }
}
