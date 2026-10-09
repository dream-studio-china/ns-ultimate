<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Inventory\Repository;


use PHPUnit\Framework\Attributes\Group;
use App\Inventory\Entity\Material;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\SpecificationRecipe;
use App\Inventory\Repository\RecipeLineRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

#[Group('low-value')]
final class RecipeLineRepositoryTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private RecipeLineRepository $repo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Inventory\\Entity\\RecipeLine line')->execute();
        $this->em->createQuery('DELETE FROM App\\Inventory\\Entity\\SpecificationRecipe recipe')->execute();
        $this->em->createQuery('DELETE FROM App\\Inventory\\Entity\\Material material')->execute();

        $repo = $this->em->getRepository(RecipeLine::class);
        self::assertInstanceOf(RecipeLineRepository::class, $repo);
        $this->repo = $repo;
    }

    /**
     * @return array{Material, SpecificationRecipe}
     */
    private function createRecipe(string $suffix, string $quantity = '1.500000'): array
    {
        $material = new Material('recipe-line-' . $suffix, 'Recipe line ' . $suffix, Material::KIND_RAW, 'kg');
        $recipe = new SpecificationRecipe(sprintf('00000000-0000-4000-8000-%012s', $suffix));
        $recipe->addLine(new RecipeLine($material, $quantity, 3));
        $this->em->persist($material);
        $this->em->persist($recipe);
        $this->em->flush();

        return [$material, $recipe];
    }

    public function testRepositoryIsInstantiatedByTheContainer(): void
    {
        $fromContainer = static::getContainer()->get(RecipeLineRepository::class);
        self::assertInstanceOf(RecipeLineRepository::class, $fromContainer);
        self::assertSame(RecipeLine::class, $fromContainer->getClassName());
    }

    public function testFindAllReturnsPersistedLines(): void
    {
        $this->createRecipe('101');
        $this->createRecipe('102');

        $lines = $this->repo->findAll();

        self::assertCount(2, $lines);
        self::assertContainsOnlyInstancesOf(RecipeLine::class, $lines);
    }

    public function testFindOneByMaterialAndFindByRecipe(): void
    {
        [$material, $recipe] = $this->createRecipe('103');

        $byMaterial = $this->repo->findOneBy(['material' => $material]);
        self::assertInstanceOf(RecipeLine::class, $byMaterial);
        self::assertSame('1.500000', $byMaterial->getQuantityPerUnit());
        self::assertSame(3, $byMaterial->getSort());
        self::assertSame($recipe, $byMaterial->getRecipe());

        $byRecipe = $this->repo->findBy(['recipe' => $recipe]);
        self::assertCount(1, $byRecipe);
        self::assertSame($material->getUuid(), $byRecipe[0]->getMaterial()->getUuid());
    }

    public function testFindByIdReturnsLineAndUnknownIdReturnsNull(): void
    {
        $this->createRecipe('104');
        $line = $this->repo->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(RecipeLine::class, $line);
        self::assertNotNull($line->getId());
        self::assertSame($line->getId(), $this->repo->find($line->getId())?->getId());
        self::assertNull($this->repo->find(999999));
    }

    public function testRemovePersistedLine(): void
    {
        [$material] = $this->createRecipe('105');
        $line = $this->repo->findOneBy(['material' => $material]);
        self::assertInstanceOf(RecipeLine::class, $line);
        $this->em->remove($line);
        $this->em->flush();

        self::assertNull($this->repo->findOneBy(['material' => $material]));
    }
}
