<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Product as CoreProduct;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\Product as StoreProduct;
use Symfony\Component\HttpFoundation\Request;

final class ProductSortOrderExtensionTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testBusinessFieldUsesExistingManageProductCrud(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())
            ->setEmail('catalog-sort@example.test')
            ->setUsername('catalog-sort')
            ->setRoles(['ROLE_ADMIN']);
        $entityManager->persist($admin);
        $entityManager->flush();

        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $request = static function (string $method, string $path, ?array $content = null) use ($token): array {
            $request = Request::create($path, $method, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ], $content === null ? null : json_encode($content, JSON_THROW_ON_ERROR));
            $response = self::$kernel->handle($request);
            self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

            return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        };

        $created = $request('POST', '/api/v1/manage/products', ['name' => 'Sorted item', 'sortOrder' => 25]);
        self::assertSame(25, $created['sortOrder']);
        self::assertArrayNotHasKey('sort', $created);
        $second = $request('POST', '/api/v1/manage/products', ['name' => 'Earlier item', 'sortOrder' => 3]);

        $entityManager->clear();
        $loadedByCoreRepository = $entityManager->getRepository(CoreProduct::class)->find($created['id']);
        self::assertInstanceOf(StoreProduct::class, $loadedByCoreRepository);
        self::assertSame(25, $loadedByCoreRepository->getSortOrder());

        $request('PUT', '/api/v1/manage/products/'.$created['id'], ['sortOrder' => 4]);
        $ordered = $request('GET', '/api/v1/manage/products?'.http_build_query([
            '@order' => 'entity.sortOrder|ASC',
        ]));

        self::assertSame([$second['id'], $created['id']], array_column($ordered, 'id'));
        self::assertSame([3, 4], array_column($ordered, 'sortOrder'));

        $coreProduct = (new CoreProduct())->setName('Core item');
        $entityManager->persist($coreProduct);
        $entityManager->flush();
        $coreId = $coreProduct->getId();
        $entityManager->clear();

        $allProducts = $request('GET', '/api/v1/manage/products');
        self::assertContains($coreId, array_column($allProducts, 'id'));
        $coreDetail = $request('GET', '/api/v1/manage/products/'.$coreId);
        self::assertSame('Core item', $coreDetail['name']);
        $updatedCore = $request('PUT', '/api/v1/manage/products/'.$coreId, ['name' => 'Updated core item']);
        self::assertSame('Updated core item', $updatedCore['name']);

        foreach (['ASC', 'DESC'] as $direction) {
            $mixed = $request('GET', '/api/v1/manage/products?'.http_build_query([
                '@order' => 'entity.sortOrder|'.$direction,
            ]));
            self::assertCount(3, $mixed);
            self::assertContains($coreId, array_column($mixed, 'id'));
            $businessRows = array_values(array_filter($mixed, static fn (array $row): bool => $row['id'] !== $coreId));
            self::assertSame($direction === 'ASC' ? [3, 4] : [4, 3], array_column($businessRows, 'sortOrder'));
        }
    }

    public function testChangingSortOrderUpdatesProductTimestamp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $product = new StoreProduct();
        $before = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');
        (new \ReflectionProperty(CoreProduct::class, 'updatedAt'))->setValue($product, $before);
        $entityManager->persist($product);
        $entityManager->flush();
        $id = $product->getId();
        $entityManager->clear();

        $product = $entityManager->find(StoreProduct::class, $id);
        self::assertNotNull($product);
        $product->setSortOrder(7);

        self::assertNotSame($before, $product->getUpdatedAt());
        self::assertNotNull($product->getUpdatedAt());
        self::assertGreaterThan($before, $product->getUpdatedAt());

        $entityManager->flush();
        $entityManager->clear();
        $loaded = $entityManager->find(StoreProduct::class, $id);
        self::assertNotNull($loaded);
        self::assertGreaterThan($before, $loaded->getUpdatedAt());
        self::assertSame(7, $loaded->getSortOrder());
    }
}
