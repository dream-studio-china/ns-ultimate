<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add revocation flags to WeChat credential revisions.';
    }

    public function up(Schema $schema): void
    {
        foreach (['wechat_application_revision', 'wechat_merchant_revision'] as $tableName) {
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('revoked')) {
                $table->addColumn('revoked', 'boolean', ['default' => false]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['wechat_application_revision', 'wechat_merchant_revision'] as $tableName) {
            if ($schema->hasTable($tableName) && $schema->getTable($tableName)->hasColumn('revoked')) {
                $schema->getTable($tableName)->dropColumn('revoked');
            }
        }
    }
}
