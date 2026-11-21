<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ToolEvents;
use NsUltimate\Business\Accounting\Repository\LedgerSchemaListener;

final class AccountingTestManager
{
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
