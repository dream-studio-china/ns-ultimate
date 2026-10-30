<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProductCategoryStoreScopeTest extends IntegrationWebTestCase
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

    public function testAdminCanChangeCategoryStoreAndReturnToGlobal(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $token = $this->createUserToken('category-store-move', ['ROLE_ADMIN']);
        $first = new Store('category-scope-a', 'Category scope A');
        $second = new Store('category-scope-b', 'Category scope B');
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();

        $created = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Beverages',
            'slug' => 'beverages',
            'store' => $first->getUuid(),
        ], $token));
        self::assertSame($first->getUuid(), $created['store']['uuid']);

        $moved = $this->data($this->request('PUT', '/api/v1/manage/product-categories/'.$created['id'], [
            'store' => $second->getUuid(),
        ], $token));
        self::assertSame($second->getUuid(), $moved['store']['uuid']);
        self::assertSame('store:'.$second->getUuid(), $moved['scopeKey']);

        $entityManager->clear();
        $loaded = $entityManager->find(ProductCategory::class, $created['id']);
        self::assertInstanceOf(ProductCategory::class, $loaded);
        self::assertSame($second->getUuid(), $loaded->getStore()?->getUuid());

        $global = $this->data($this->request('PUT', '/api/v1/manage/product-categories/'.$created['id'], [
            'store' => null,
        ], $token));
        self::assertNull($global['store']);
        self::assertSame('global', $global['scopeKey']);

        $numeric = $this->data($this->request('PUT', '/api/v1/manage/product-categories/'.$created['id'], [
            'store' => $first->getId(),
        ], $token));
        self::assertSame($first->getUuid(), $numeric['store']['uuid']);

        $invalid = $this->request('PUT', '/api/v1/manage/product-categories/'.$created['id'], [
            'store' => '00000000-0000-0000-0000-000000000000',
        ], $token);
        self::assertSame(400, $invalid->getStatusCode());
    }

    public function testCategoryWithChildrenCannotChangeStoreUntilDetachedFromThem(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $token = $this->createUserToken('category-store-children', ['ROLE_ADMIN']);
        $first = new Store('category-children-a', 'Category children A');
        $second = new Store('category-children-b', 'Category children B');
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();

        $parent = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Food',
            'slug' => 'food',
            'store' => $first->getUuid(),
        ], $token));
        $child = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Dessert',
            'slug' => 'dessert',
            'store' => $first->getUuid(),
            'parent' => $parent['uuid'],
        ], $token));
        self::assertSame($parent['uuid'], $child['parent']['uuid']);

        $blocked = $this->request('PUT', '/api/v1/manage/product-categories/'.$parent['id'], [
            'store' => $second->getUuid(),
        ], $token);
        self::assertSame(400, $blocked->getStatusCode());

        $entityManager->clear();
        self::assertSame(
            $first->getUuid(),
            $entityManager->find(ProductCategory::class, $parent['id'])?->getStore()?->getUuid(),
        );

        $movedChild = $this->data($this->request('PUT', '/api/v1/manage/product-categories/'.$child['id'], [
            'store' => $second->getUuid(),
            'parent' => null,
        ], $token));
        self::assertSame($second->getUuid(), $movedChild['store']['uuid']);
        self::assertNull($movedChild['parent']);

        $detached = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Drinks',
            'slug' => 'drinks',
            'store' => $first->getUuid(),
            'parent' => $parent['uuid'],
        ], $token));
        $detachedOnly = $this->data($this->request('PUT', '/api/v1/manage/product-categories/'.$detached['id'], [
            'parent' => null,
        ], $token));
        self::assertNull($detachedOnly['parent']);
        self::assertSame($first->getUuid(), $detachedOnly['store']['uuid']);
    }

    public function testCategoryCanChangeStoreTogetherWithItsParent(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $token = $this->createUserToken('category-store-parent', ['ROLE_ADMIN']);
        $first = new Store('category-parent-a', 'Category parent A');
        $second = new Store('category-parent-b', 'Category parent B');
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();

        $category = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Shoes',
            'slug' => 'shoes',
            'store' => $first->getUuid(),
        ], $token));
        $newParent = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Fashion',
            'slug' => 'fashion',
            'store' => $second->getUuid(),
        ], $token));

        $moved = $this->data($this->request('PUT', '/api/v1/manage/product-categories/'.$category['id'], [
            'store' => $second->getUuid(),
            'parent' => $newParent['uuid'],
        ], $token));
        self::assertSame($second->getUuid(), $moved['store']['uuid']);
        self::assertSame($newParent['uuid'], $moved['parent']['uuid']);

        $mismatchedParent = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Footwear',
            'slug' => 'footwear',
            'store' => $first->getUuid(),
        ], $token));
        $blocked = $this->request('PUT', '/api/v1/manage/product-categories/'.$category['id'], [
            'store' => $second->getUuid(),
            'parent' => $mismatchedParent['uuid'],
        ], $token);
        self::assertSame(400, $blocked->getStatusCode());
    }

    public function testCategoryWithProductsFromAnotherStoreCannotChangeStore(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $token = $this->createUserToken('category-store-products', ['ROLE_ADMIN']);
        $first = new Store('category-product-a', 'Category product A');
        $second = new Store('category-product-b', 'Category product B');
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();

        $category = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Snacks',
            'slug' => 'snacks',
            'store' => $first->getUuid(),
        ], $token));
        $this->data($this->request('POST', '/api/v1/manage/products', [
            'name' => 'Category-linked product',
            'store' => $first->getUuid(),
            'category' => $category['uuid'],
        ], $token));

        $blocked = $this->request('PUT', '/api/v1/manage/product-categories/'.$category['id'], [
            'store' => $second->getUuid(),
        ], $token);
        self::assertSame(400, $blocked->getStatusCode());

        $entityManager->clear();
        self::assertSame(
            $first->getUuid(),
            $entityManager->find(ProductCategory::class, $category['id'])?->getStore()?->getUuid(),
        );

        $global = $this->data($this->request('PUT', '/api/v1/manage/product-categories/'.$category['id'], [
            'store' => null,
        ], $token));
        self::assertNull($global['store']);
    }

    public function testNonAdminCannotChangeCategoryStore(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $adminToken = $this->createUserToken('category-store-admin', ['ROLE_ADMIN']);
        $userToken = $this->createUserToken('category-store-user', ['ROLE_USER']);
        $first = new Store('category-auth-a', 'Category auth A');
        $second = new Store('category-auth-b', 'Category auth B');
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();

        $category = $this->data($this->request('POST', '/api/v1/manage/product-categories', [
            'name' => 'Protected',
            'slug' => 'protected',
            'store' => $first->getUuid(),
        ], $adminToken));

        self::assertSame(401, $this->request('PUT', '/api/v1/manage/product-categories/'.$category['id'], [
            'store' => $second->getUuid(),
        ])->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v1/manage/product-categories/'.$category['id'], [
            'store' => $second->getUuid(),
        ], $userToken)->getStatusCode());
    }

    /** @param list<string> $roles */
    private function createUserToken(string $username, array $roles): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail($username.'@example.test')
            ->setUsername($username)
            ->setRoles($roles);
        $entityManager->persist($user);
        $entityManager->flush();

        return self::getContainer()->get(TokenManager::class)->createAccessToken($user);
    }

    /** @param array<string, mixed>|null $content */
    private function request(string $method, string $path, ?array $content = null, ?string $token = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return self::$kernel->handle(Request::create(
            $path,
            $method,
            [],
            [],
            [],
            $server,
            $content === null ? null : json_encode($content, JSON_THROW_ON_ERROR),
        ));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
