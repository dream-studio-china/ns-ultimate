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

        $created = $request('POST', '/api/v1/manage/products', [
            'name' => 'Sorted item',
            'sortOrder' => 25,
            'recommendationLevel' => 3,
            'isSoldout' => true,
            'metadata' => ['subtitle' => 'Limited offer', 'cover' => 'https://cdn.example.test/product.webp'],
            'settings' => [
                'kitchenPrinterEnable' => true,
                'startSaleTime' => '09:30',
                'endSaleTime' => '21:45',
            ],
        ]);
        self::assertSame(25, $created['sortOrder']);
        self::assertSame(3, $created['recommendationLevel']);
        self::assertTrue($created['isSoldout']);
        self::assertSame('Limited offer', $created['metadata']['subtitle']);
        self::assertSame('https://cdn.example.test/product.webp', $created['metadata']['cover']);
        self::assertSame(true, $created['settings']['kitchenPrinterEnable']);
        self::assertSame('09:30', $created['settings']['startSaleTime']);
        self::assertArrayNotHasKey('sort', $created);
        $second = $request('POST', '/api/v1/manage/products', ['name' => 'Earlier item', 'sortOrder' => 3]);

        $entityManager->clear();
        $loadedByCoreRepository = $entityManager->getRepository(CoreProduct::class)->find($created['id']);
        self::assertInstanceOf(StoreProduct::class, $loadedByCoreRepository);
        self::assertSame(25, $loadedByCoreRepository->getSortOrder());
        self::assertSame(3, $loadedByCoreRepository->getRecommendationLevel());
        self::assertTrue($loadedByCoreRepository->isSoldout());
        self::assertSame(['kitchenPrinterEnable' => true, 'startSaleTime' => '09:30', 'endSaleTime' => '21:45'], $loadedByCoreRepository->getSettings());

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

    public function testProductSettingsAndMetadataSchemasRejectInvalidValues(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())
            ->setEmail('product-schema@example.test')
            ->setUsername('product-schema')
            ->setRoles(['ROLE_ADMIN']);
        $entityManager->persist($admin);
        $entityManager->flush();

        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $post = static function (array $content) use ($token): int {
            $request = Request::create('/api/v1/manage/products', 'POST', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ], json_encode($content, JSON_THROW_ON_ERROR));
            $response = self::$kernel->handle($request);

            return $response->getStatusCode();
        };

        self::assertSame(400, $post(['name' => 'Invalid printer', 'settings' => ['kitchenPrinterEnable' => 'yes']]));
        self::assertSame(400, $post(['name' => 'Invalid time', 'settings' => ['startSaleTime' => '25:90']]));
        self::assertSame(400, $post(['name' => 'Invalid cover', 'metadata' => ['cover' => 'javascript:alert(1)']]));
        self::assertSame(201, $post(['name' => 'Defaults']));

        $entityManager->clear();
        $products = $entityManager->getRepository(StoreProduct::class)->findBy(['name' => 'Defaults']);
        self::assertCount(1, $products);
        self::assertSame(0, $products[0]->getRecommendationLevel());
        self::assertFalse($products[0]->isSoldout());
        self::assertNull($products[0]->getSettings());
    }
}
