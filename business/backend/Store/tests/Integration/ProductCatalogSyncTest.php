<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProductCatalogSyncTest extends IntegrationWebTestCase
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

    public function testPreviewAndSyncCopyCatalogWithDuplicateProtectionAndSpecifications(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$admin, $source, $target] = $this->createStoresAndAdmin($entityManager, 'catalog-sync');
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);

        $food = (new ProductCategory('Food', 'food'))->setStore($source)->setSortOrder(1)->setEnabled(true);
        $mains = (new ProductCategory('Mains', 'mains'))->setStore($source)->setParent($food)->setDescription('Main dishes')->setSortOrder(2);
        $shared = (new ProductCategory(' Shared ', 'source-shared'))->setStore($source)->setDescription('Source copy');
        $globalShared = (new ProductCategory('Global Shared', 'source-global-shared'))->setStore($source);
        $drinks = (new ProductCategory('Beverages', 'drinks'))->setStore($source)->setSortOrder(3)->setEnabled(false);
        $targetShared = (new ProductCategory('shared', 'shared'))->setStore($target)->setDescription('Target original');
        $targetDrinkSlug = (new ProductCategory('Existing drinks', 'drinks'))->setStore($target);
        $globalSharedExisting = (new ProductCategory(' global shared ', 'global-shared'))->setStore(null);
        foreach ([$food, $mains, $shared, $globalShared, $drinks, $targetShared, $targetDrinkSlug, $globalSharedExisting] as $category) {
            $entityManager->persist($category);
        }

        $product = (new Product($source))
            ->setName('Signature noodles')
            ->setDescription('Fresh noodles')
            ->setCategory($mains)
            ->setStatus(Product::STATUS_INACTIVE)
            ->setMetadata(['cover' => 'https://cdn.example.test/noodles.webp', 'subtitle' => 'Daily'])
            ->setSortOrder(12)
            ->setRecommendationLevel(4)
            ->setIsSoldout(true)
            ->setSettings(['kitchenPrinterEnable' => true, 'startSaleTime' => '09:00', 'endSaleTime' => '20:30']);
        $activeSpecification = (new Specification())->setName('Large')->setPrice(2680)->setStatus(Specification::STATUS_ACTIVE)->setSort(2);
        $deletedSpecification = (new Specification())->setName('Deleted size')->setPrice(1000)->setStatus(Specification::STATUS_INACTIVE)->setSort(3)->setIsDeleted(true);
        $product->addSpecification($activeSpecification)->addSpecification($deletedSpecification);

        $sameNameProduct = (new Product($source))->setName(' TARGET PRODUCT ')->setCategory($food);
        $globalNameProduct = (new Product($source))->setName('Global Product')->setCategory($food);
        $deletedProduct = (new Product($source))->setName('Deleted source product')->setIsDeleted(true);
        $targetProduct = (new Product($target))->setName('target product')->setCategory($targetShared);
        $globalProduct = (new Product(null))->setName('GLOBAL PRODUCT');
        foreach ([$product, $sameNameProduct, $globalNameProduct, $deletedProduct, $targetProduct, $globalProduct] as $item) {
            $entityManager->persist($item);
        }
        $entityManager->flush();

        $payload = ['sourceStoreUuid' => $source->getUuid(), 'targetStoreUuid' => $target->getUuid()];
        $preview = $this->json($this->request('POST', '/api/v1/manage/product-catalog-sync/preview', $payload, $token));
        self::assertSame(3, $preview['categories']['toCreate']);
        self::assertSame(2, $preview['categories']['skipped']);
        self::assertSame(1, $preview['products']['toCreate']);
        self::assertSame(2, $preview['products']['skipped']);
        self::assertContains(' Shared ', $preview['categories']['skippedNames']);
        self::assertContains('Global Shared', $preview['categories']['skippedNames']);
        self::assertContains(' TARGET PRODUCT ', $preview['products']['skippedNames']);
        self::assertContains('Global Product', $preview['products']['skippedNames']);
        self::assertCount(2, $entityManager->getRepository(ProductCategory::class)->findBy(['store' => $target]));
        self::assertCount(1, $entityManager->getRepository(Product::class)->findBy(['store' => $target]));

        $synced = $this->json($this->request('POST', '/api/v1/manage/product-catalog-sync', $payload, $token));
        self::assertSame(3, $synced['categories']['created']);
        self::assertSame(2, $synced['categories']['skipped']);
        self::assertSame(1, $synced['products']['created']);
        self::assertSame(2, $synced['products']['skipped']);
        self::assertSame(1, $synced['products']['specificationsCreated']);

        $entityManager->clear();
        $targetFood = $entityManager->getRepository(ProductCategory::class)->findOneBy(['store' => $target, 'slug' => 'food']);
        self::assertInstanceOf(ProductCategory::class, $targetFood);
        $targetMains = $entityManager->getRepository(ProductCategory::class)->findOneBy(['store' => $target, 'slug' => 'mains']);
        self::assertInstanceOf(ProductCategory::class, $targetMains);
        self::assertSame($targetFood->getId(), $targetMains->getParent()?->getId());
        self::assertSame('Main dishes', $targetMains->getDescription());

        $renamedSlug = $entityManager->getRepository(ProductCategory::class)->findOneBy(['store' => $target, 'slug' => 'drinks-sync']);
        self::assertInstanceOf(ProductCategory::class, $renamedSlug);
        self::assertFalse($renamedSlug->isEnabled());
        self::assertSame('Target original', $entityManager->getRepository(ProductCategory::class)->findOneBy(['store' => $target, 'slug' => 'shared'])?->getDescription());

        $copiedProduct = $entityManager->getRepository(Product::class)->findOneBy(['store' => $target, 'name' => 'Signature noodles']);
        self::assertInstanceOf(Product::class, $copiedProduct);
        self::assertNotSame($product->getUuid(), $copiedProduct->getUuid());
        self::assertSame('Fresh noodles', $copiedProduct->getDescription());
        self::assertSame(Product::STATUS_INACTIVE, $copiedProduct->getStatus());
        self::assertSame(['cover' => 'https://cdn.example.test/noodles.webp', 'subtitle' => 'Daily'], $copiedProduct->getMetadata());
        self::assertSame(12, $copiedProduct->getSortOrder());
        self::assertSame(4, $copiedProduct->getRecommendationLevel());
        self::assertTrue($copiedProduct->getIsSoldout());
        self::assertSame(['kitchenPrinterEnable' => true, 'startSaleTime' => '09:00', 'endSaleTime' => '20:30'], $copiedProduct->getSettings());
        self::assertSame($targetMains->getId(), $copiedProduct->getCategory()?->getId());
        self::assertCount(1, $copiedProduct->getSpecifications());
        $copiedSpecification = $copiedProduct->getSpecifications()->first();
        self::assertInstanceOf(Specification::class, $copiedSpecification);
        self::assertSame('Large', $copiedSpecification->getName());
        self::assertSame(2680, $copiedSpecification->getPrice());
        self::assertSame(2, $copiedSpecification->getSort());

        $secondPreview = $this->json($this->request('POST', '/api/v1/manage/product-catalog-sync/preview', $payload, $token));
        self::assertSame(0, $secondPreview['categories']['toCreate']);
        self::assertSame(5, $secondPreview['categories']['skipped']);
        self::assertSame(0, $secondPreview['products']['toCreate']);
        self::assertSame(3, $secondPreview['products']['skipped']);
        $secondSync = $this->json($this->request('POST', '/api/v1/manage/product-catalog-sync', $payload, $token));
        self::assertSame(0, $secondSync['categories']['created']);
        self::assertSame(0, $secondSync['products']['created']);
        self::assertCount(5, $entityManager->getRepository(ProductCategory::class)->findBy(['store' => $target]));
        self::assertCount(2, $entityManager->getRepository(Product::class)->findBy(['store' => $target]));
    }

    public function testSyncRejectsSameOrMissingStores(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$admin, $source] = $this->createStoresAndAdmin($entityManager, 'catalog-validation');
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);

        $sameStore = $this->request('POST', '/api/v1/manage/product-catalog-sync', [
            'sourceStoreUuid' => $source->getUuid(),
            'targetStoreUuid' => $source->getUuid(),
        ], $token);
        self::assertSame(422, $sameStore->getStatusCode());

        $missingStore = $this->request('POST', '/api/v1/manage/product-catalog-sync/preview', [
            'sourceStoreUuid' => $source->getUuid(),
            'targetStoreUuid' => '00000000-0000-4000-8000-000000000000',
        ], $token);
        self::assertSame(422, $missingStore->getStatusCode());
    }

    /** @return array{0: User, 1: Store, 2?: Store} */
    private function createStoresAndAdmin(EntityManagerInterface $entityManager, string $suffix): array
    {
        $admin = (new User())
            ->setEmail($suffix.'@example.test')
            ->setUsername($suffix)
            ->setRoles(['ROLE_ADMIN']);
        $source = new Store($suffix.'-source', $suffix.' source');
        $target = new Store($suffix.'-target', $suffix.' target');
        $entityManager->persist($admin);
        $entityManager->persist($source);
        $entityManager->persist($target);
        $entityManager->flush();

        return [$admin, $source, $target];
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body, string $token): Response
    {
        return self::$kernel->handle(Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
