<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Migrations\Version\Version;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminAgentRetirementTest extends KernelTestCase
{
    public function testAssistantBackendIsAbsentAndRetirementMigrationsRemainDiscoverable(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        foreach ($container->get('router')->getRouteCollection() as $route) {
            self::assertStringNotContainsString('/manage/agent/', $route->getPath());
        }
        $em = $container->get(EntityManagerInterface::class);
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            self::assertNotContains($metadata->getTableName(), ['admin_agent_operation', 'admin_agent_batch_operation']);
        }
        self::assertFileDoesNotExist(dirname(__DIR__, 3).'/apps/admin-agent/backend/composer.json');
        $versions = $container->get('doctrine.migrations.dependency_factory')->getMigrationRepository()->getMigrations();
        foreach ([
            'NsUltimate\\Apps\\AdminAgent\\Backend\\Migrations\\Version20261022120000',
            'NsUltimate\\Apps\\AdminAgent\\Backend\\Migrations\\Version20261023120000',
            'NsUltimate\\Integration\\Backend\\Migrations\\Version20261024120000',
        ] as $version) {
            self::assertTrue($versions->hasMigration(new Version($version)));
        }
    }
}
