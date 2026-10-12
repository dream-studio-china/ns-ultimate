<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pin partner identifiers to payment attempts and purge unused WeChat session material.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('wechat_payment_attempt');
        if (!$table->hasColumn('sub_merchant_id')) {
            $table->addColumn('sub_merchant_id', 'string', ['length' => 64, 'notnull' => false]);
        }
        if (!$table->hasColumn('sub_app_id')) {
            $table->addColumn('sub_app_id', 'string', ['length' => 64, 'notnull' => false]);
        }
        if ($schema->hasTable('wechat_identity')) {
            $identity = $schema->getTable('wechat_identity');
            if ($identity->hasColumn('raw_data')) {
                $this->addSql('UPDATE wechat_identity SET raw_data = NULL');
                $identity->dropColumn('raw_data');
            }
            if ($identity->hasColumn('session_key_encrypted')) {
                $this->addSql('UPDATE wechat_identity SET session_key_encrypted = NULL');
                $identity->dropColumn('session_key_encrypted');
            }
            if ($identity->hasColumn('session_key_key_id')) {
                $this->addSql('UPDATE wechat_identity SET session_key_key_id = NULL');
                $identity->dropColumn('session_key_key_id');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('wechat_payment_attempt')) {
            $table = $schema->getTable('wechat_payment_attempt');
            foreach (['sub_merchant_id', 'sub_app_id'] as $column) {
                if ($table->hasColumn($column)) {
                    $table->dropColumn($column);
                }
            }
        }
        if ($schema->hasTable('wechat_identity')) {
            $identity = $schema->getTable('wechat_identity');
            if (!$identity->hasColumn('raw_data')) {
                $identity->addColumn('raw_data', 'json', ['notnull' => false]);
            }
            if (!$identity->hasColumn('session_key_encrypted')) {
                $identity->addColumn('session_key_encrypted', 'text', ['notnull' => false]);
            }
            if (!$identity->hasColumn('session_key_key_id')) {
                $identity->addColumn('session_key_key_id', 'string', ['length' => 32, 'notnull' => false]);
            }
        }
    }
}
