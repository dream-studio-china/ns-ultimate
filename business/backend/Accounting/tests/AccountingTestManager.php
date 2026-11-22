<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ToolEvents;
use NsUltimate\Business\Accounting\Repository\LedgerSchemaListener;
use Psr\Log\NullLogger;

final class AccountingTestManager
{
    /** @param class-string<AbstractMigration> ...$migrations */
    public static function migrate(Connection $connection, string ...$migrations): void
    {
        foreach ($migrations as $class) {
            require_once dirname(__DIR__).'/migrations/'.substr(strrchr($class, '\\'), 1).'.php';
            $migration = new $class($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) {
                $connection->executeStatement($sql->getStatement());
            }
        }
    }

    public static function create(Connection $connection): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        $config->enableNativeLazyObjects(true);
        $manager = new EntityManager($connection, $config);
        $manager->getEventManager()->addEventListener([ToolEvents::postGenerateSchema], new LedgerSchemaListener());

        return $manager;
    }
}
