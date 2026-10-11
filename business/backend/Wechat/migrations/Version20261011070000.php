<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add WeChat partner sub-merchant binding fields and payment/refund attempts.';
    }

    public function up(Schema $schema): void
    {
        $setting = $schema->getTable('store_wechat_setting');
        $setting->addColumn('sub_merchant_id', 'string', ['length' => 64, 'notnull' => false]);
        $setting->addColumn('sub_app_id', 'string', ['length' => 64, 'notnull' => false]);

        $payment = $schema->createTable('wechat_payment_attempt');
        $payment->addColumn('id', 'integer', ['autoincrement' => true]);
        $payment->addColumn('uuid', 'string', ['length' => 36]);
        $payment->addColumn('invoice_uuid', 'string', ['length' => 36]);
        $payment->addColumn('store_uuid', 'string', ['length' => 36]);
        $payment->addColumn('application_revision_id', 'integer', ['notnull' => false]);
        $payment->addColumn('merchant_revision_id', 'integer', ['notnull' => false]);
        $payment->addColumn('provider_order_no', 'string', ['length' => 64]);
        $payment->addColumn('trade_type', 'string', ['length' => 20]);
        $payment->addColumn('expected_amount', 'bigint', ['default' => 0]);
        $payment->addColumn('currency', 'string', ['length' => 8, 'default' => 'CNY']);
        $payment->addColumn('payer_identity_id', 'integer', ['notnull' => false]);
        $payment->addColumn('idempotency_key', 'string', ['length' => 64]);
        $payment->addColumn('status', 'string', ['length' => 20, 'default' => 'created']);
        $payment->addColumn('transaction_id', 'string', ['length' => 128, 'notnull' => false]);
        $payment->addColumn('created_at', 'datetime_immutable');
        $payment->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $payment->setPrimaryKey(['id']);
        $payment->addUniqueIndex(['uuid'], 'uniq_wechat_payment_attempt_uuid');
        $payment->addUniqueIndex(['provider_order_no'], 'uniq_wechat_payment_attempt_order_no');
        $payment->addUniqueIndex(['idempotency_key'], 'uniq_wechat_payment_attempt_idempotency');
        $payment->addIndex(['invoice_uuid'], 'idx_wechat_payment_attempt_invoice');
        $payment->addForeignKeyConstraint('wechat_application_revision', ['application_revision_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_wechat_payment_attempt_application');
        $payment->addForeignKeyConstraint('wechat_merchant_revision', ['merchant_revision_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_wechat_payment_attempt_merchant');
        $payment->addForeignKeyConstraint('wechat_identity', ['payer_identity_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_wechat_payment_attempt_payer');

        $refund = $schema->createTable('wechat_refund_attempt');
        $refund->addColumn('id', 'integer', ['autoincrement' => true]);
        $refund->addColumn('uuid', 'string', ['length' => 36]);
        $refund->addColumn('payment_attempt_id', 'integer');
        $refund->addColumn('provider_refund_no', 'string', ['length' => 64]);
        $refund->addColumn('idempotency_key', 'string', ['length' => 64]);
        $refund->addColumn('amount', 'bigint', ['default' => 0]);
        $refund->addColumn('status', 'string', ['length' => 20, 'default' => 'requested']);
        $refund->addColumn('provider_refund_id', 'string', ['length' => 128, 'notnull' => false]);
        $refund->addColumn('accounting_applied_at', 'datetime_immutable', ['notnull' => false]);
        $refund->addColumn('created_at', 'datetime_immutable');
        $refund->addColumn('updated_at', 'datetime_immutable', ['notnull' => false]);
        $refund->setPrimaryKey(['id']);
        $refund->addUniqueIndex(['uuid'], 'uniq_wechat_refund_attempt_uuid');
        $refund->addUniqueIndex(['provider_refund_no'], 'uniq_wechat_refund_attempt_refund_no');
        $refund->addUniqueIndex(['idempotency_key'], 'uniq_wechat_refund_attempt_idempotency');
        $refund->addIndex(['payment_attempt_id'], 'idx_wechat_refund_attempt_payment');
        $refund->addForeignKeyConstraint('wechat_payment_attempt', ['payment_attempt_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_wechat_refund_attempt_payment');
    }

    public function down(Schema $schema): void
    {
        foreach (['wechat_refund_attempt', 'wechat_payment_attempt'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }

        $setting = $schema->getTable('store_wechat_setting');
        foreach (['sub_merchant_id', 'sub_app_id'] as $column) {
            if ($setting->hasColumn($column)) {
                $setting->dropColumn($column);
            }
        }
    }
}
