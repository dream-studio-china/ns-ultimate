<?php

declare(strict_types=1);

namespace NsUltimate\Business\Dummy\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Dummy\Entity\Dummy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;

final class DummyModuleTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ($entityManager->getConnection()->createSchemaManager()->listTableNames() !== []) {
            $schemaTool->dropSchema($metadata);
        }
        $schemaTool->createSchema($metadata);
    }

    public function testBusinessRoutesAndEntityMappingAreRegistered(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();
        $entityMetadata = self::getContainer()
            ->get(EntityManagerInterface::class)
            ->getMetadataFactory()
            ->getAllMetadata();
        $entityNames = array_map(static fn ($metadata): string => $metadata->getName(), $entityMetadata);

        self::assertInstanceOf(Route::class, $routes->get('business-dummies-list'));
        self::assertInstanceOf(Route::class, $routes->get('business-dummies-create'));
        self::assertContains(Dummy::class, $entityNames);
    }

    public function testBusinessEntityCanBePersistedAndReadBack(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $dummy = (new Dummy())->setTitle('Integration test')->setBody('Persisted outside the core subtree.');
        $entityManager->persist($dummy);
        $entityManager->flush();
        $id = $dummy->getId();
        $entityManager->clear();

        $reloaded = $entityManager->find(Dummy::class, $id);

        self::assertInstanceOf(Dummy::class, $reloaded);
        self::assertSame('Integration test', $reloaded->getTitle());
        self::assertSame('Persisted outside the core subtree.', $reloaded->getBody());
    }
}
