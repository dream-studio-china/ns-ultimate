<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Service;

use App\Inventory\Entity\Material;
use App\Inventory\Service\SpecificationRecipeServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class SpecificationRecipeServiceTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ([
            'App\\Inventory\\Entity\\InventoryOutboxMessage',
            'App\\Inventory\\Entity\\InventoryConsumedEvent',
            'App\\Inventory\\Entity\\LedgerEntry',
            'App\\Inventory\\Entity\\ReservationLine',
            'App\\Inventory\\Entity\\Reservation',
            'App\\Inventory\\Entity\\RecipeLine',
            'App\\Inventory\\Entity\\SpecificationRecipe',
            'App\\Inventory\\Entity\\Stock',
            'App\\Inventory\\Entity\\Material',
        ] as $entity) {
            $em->createQuery('DELETE FROM ' . $entity . ' entity')->execute();
        }
        self::ensureKernelShutdown();
    }

    public function testCreateRecipeRejectsDuplicateSpecificationUuid(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $material = new Material('sr-material', 'SR Material', Material::KIND_RAW, 'kg');
        $em->persist($material);
        $em->flush();

        $service = $container->get(SpecificationRecipeServiceInterface::class);
        $recipe = $service->createRecipe('00000000-0000-4000-8000-000000000701', [
            ['materialUuid' => $material->getUuid(), 'quantityPerUnit' => '1.000000'],
        ]);
        self::assertSame('00000000-0000-4000-8000-000000000701', $recipe->getSpecificationUuid());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A recipe already exists for this specification.');
        $service->createRecipe('00000000-0000-4000-8000-000000000701', [
            ['materialUuid' => $material->getUuid(), 'quantityPerUnit' => '1.000000'],
        ]);
    }

    public function testCreateRecipeRejectsMissingOrInactiveMaterial(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $service = $container->get(SpecificationRecipeServiceInterface::class);

        $inactive = new Material('sr-inactive', 'SR Inactive', Material::KIND_RAW, 'kg');
        $inactive->setStatus(Material::STATUS_INACTIVE);
        $em->persist($inactive);
        $em->flush();

        try {
            $service->createRecipe('00000000-0000-4000-8000-000000000711', [
                ['materialUuid' => '00000000-0000-4000-8000-000000000712', 'quantityPerUnit' => '1.000000'],
            ]);
            self::fail('Expected missing material rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('was not found or is inactive', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->createRecipe('00000000-0000-4000-8000-000000000713', [
            ['materialUuid' => $inactive->getUuid(), 'quantityPerUnit' => '1.000000'],
        ]);
    }
}
