<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use App\Common\Entity\Content as CoreContent;
use App\Store\Entity\Product as CoreProduct;
use App\Store\Service\ProductServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Common\Entity\Content;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Integration\Backend\Doctrine\EntityExtensionQueryBuilderFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Exception\ValidatorException;

final class EntityExtensionQueryTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testCallerQueryAliasAndProgrammaticOrderingPreserveMixedRows(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $core = (new CoreProduct())->setName('Root');
        $earlier = (new Product())->setName('Earlier')->setSortOrder(2);
        $later = (new Product())->setName('Later')->setSortOrder(9);
        foreach ([$core, $earlier, $later] as $product) {
            $entityManager->persist($product);
        }
        $entityManager->flush();

        $queryBuilder = $entityManager->createQueryBuilder()->select('p')->from(CoreProduct::class, 'p');
        $service = self::getContainer()->get(ProductServiceInterface::class);
        $rows = $service->list($queryBuilder, ['entity.sortOrder' => 'DESC', 'p.name' => 'ASC']);
        self::assertContains($core, $rows);
        $businessRows = array_values(array_filter($rows, static fn (CoreProduct $row): bool => $row instanceof Product));
        self::assertSame([$later, $earlier], $businessRows);

        $factory = $this->factory($entityManager);
        $factory->joinExtensions($queryBuilder);
        self::assertCount(1, $queryBuilder->getDQLPart('join')['p']);
        self::assertStringNotContainsString('entity.sortOrder', $queryBuilder->getDQL());
    }

    public function testOnlyDeclaredFieldsAndRootTypesAreAdapted(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $factory = $this->factory($entityManager);
        self::assertSame('entity.name|ASC', $factory->orderExpression('entity.name|ASC', CoreProduct::class));
        self::assertSame('entity.sortOrderExtra|ASC,entity.category.sortOrder|DESC', $factory->orderExpression(
            'entity.sortOrderExtra|ASC,entity.category.sortOrder|DESC', CoreProduct::class,
        ));
        self::assertSame('entity.sortOrder|ASC', $factory->orderExpression('entity.sortOrder|ASC', Product::class));
        $queryBuilder = $factory->createRootQueryBuilder(Product::class);
        self::assertSame([], $queryBuilder->getDQLPart('join'));

        $withoutExtensions = new EntityExtensionQueryBuilderFactory($entityManager, []);
        self::assertSame([], $withoutExtensions->createRootQueryBuilder(CoreProduct::class)->getDQLPart('join'));
        self::assertSame('entity.sortOrder|ASC', $withoutExtensions->orderExpression('entity.sortOrder|ASC', CoreProduct::class));

        $contentQuery = $factory->createRootQueryBuilder(CoreContent::class, 'c');
        self::assertStringContainsString(Content::class, $contentQuery->getDQL());
        self::assertStringNotContainsString(Product::class, $contentQuery->getDQL());
    }

    public function testQueryAliasCollisionFailsInsteadOfReusingAnUnrelatedJoin(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $factory = $this->factory($entityManager);
        $reference = $factory->createRootQueryBuilder(CoreProduct::class, 'p');
        $alias = $reference->getDQLPart('join')['p'][0]->getAlias();
        $collision = $entityManager->createQueryBuilder()->select('p')->from(CoreProduct::class, 'p')
            ->leftJoin(CoreContent::class, $alias, 'WITH', 'p.id = '.$alias.'.id');

        $this->expectException(\LogicException::class);
        $factory->joinExtensions($collision);
    }

    public function testRequestOrderIsRestoredOnSuccessAndFailure(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(ProductServiceInterface::class);
        $stack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/api/v1/manage/products', 'GET', ['@order' => 'entity.sortOrder|DESC,entity.name|ASC']);
        $originalOrder = $request->query->get('@order');
        $stack->push($request);
        try {
            $result = $service->list(null, null, false);
            self::assertInstanceOf(QueryBuilder::class, $result);
            self::assertSame($originalOrder, $request->query->get('@order'));
            self::assertStringNotContainsString('entity.sortOrder', $result->getDQL());

            $request->query->set('@select', []);
            try {
                $service->list(null, null, false);
                self::fail('An invalid selection must fail.');
            } catch (ValidatorException) {
                self::assertSame($originalOrder, $request->query->get('@order'));
            }
        } finally {
            $stack->pop();
        }
    }

    private function factory(EntityManagerInterface $entityManager): EntityExtensionQueryBuilderFactory
    {
        return new EntityExtensionQueryBuilderFactory($entityManager, [
            CoreProduct::class => ['sortOrder' => Product::class],
            CoreContent::class => ['sortOrder' => Content::class],
        ]);
    }
}
