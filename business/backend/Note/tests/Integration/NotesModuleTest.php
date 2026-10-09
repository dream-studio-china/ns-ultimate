<?php

declare(strict_types=1);

namespace NsUltimate\Business\Note\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Note\Entity\Note;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;

final class NotesModuleTest extends KernelTestCase
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

        self::assertInstanceOf(Route::class, $routes->get('business-notes-list'));
        self::assertInstanceOf(Route::class, $routes->get('business-notes-create'));
        self::assertContains(Note::class, $entityNames);
    }

    public function testBusinessEntityCanBePersistedAndReadBack(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $note = (new Note())->setTitle('Integration test')->setBody('Persisted outside the core subtree.');
        $entityManager->persist($note);
        $entityManager->flush();
        $id = $note->getId();
        $entityManager->clear();

        $reloaded = $entityManager->find(Note::class, $id);

        self::assertInstanceOf(Note::class, $reloaded);
        self::assertSame('Integration test', $reloaded->getTitle());
        self::assertSame('Persisted outside the core subtree.', $reloaded->getBody());
    }
}
