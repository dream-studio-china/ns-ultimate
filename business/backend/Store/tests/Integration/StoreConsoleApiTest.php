<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Verifies the existing employee contracts consumed by the independent mobile app. */
final class StoreConsoleApiTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    public function testStaffLoginCatalogTablesOrdersAndStoreIsolation(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $user = (new User())->setEmail('console-contract@example.test')->setUsername('console-contract');
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'contract-test-only-password'));
        $store = new Store('console-contract', 'Console contract store');
        $other = new Store('console-other', 'Other store');
        $role = new Role('console-contract', 'Console contract staff', Role::SCOPE_STORE);
        foreach (['product', 'product_category', 'specification', 'counter', 'order'] as $resource) {
            foreach ($resource === 'order' ? ['read'] : ['read', 'create', 'update', 'delete'] as $action) {
                $permission = new Permission("store:$resource:$action", 'store', $resource, $action, "$resource $action");
                $role->addPermission($permission);
                $em->persist($permission);
            }
        }
        foreach ([$user, $store, $other, $role, new Membership($store, $user->getUuid(), Membership::ROLE_MANAGER), new Assignment($role, $user->getUuid(), Assignment::SCOPE_STORE, $store->getUuid())] as $entity) {
            $em->persist($entity);
        }
        $order = new StoreOrder($store, UUID::v4(), $store->getCode(), $store->getName(), null, 'CNY', 1250, ['items' => []]);
        $em->persist($order);
        $em->flush();

        $login = $this->json($this->request('POST', '/api/auth/login', ['identifier' => 'console-contract', 'password' => 'contract-test-only-password']));
        $token = $login['access_token'];
        self::assertIsString($token);
        $access = $this->data($this->request('GET', '/api/v1/app/authorization/me', null, $token));
        self::assertContains($store->getUuid(), $access['storeScopes']['store:product:read']);
        self::assertSame('active', $this->data($this->request('GET', '/api/v1/app/stores/'.$store->getUuid().'/membership', null, $token))['status']);
        self::assertSame($store->getUuid(), $this->data($this->request('GET', '/api/v1/app/stores/'.$store->getUuid(), null, $token))['uuid']);
        self::assertSame(404, $this->request('GET', '/api/v1/app/stores/'.$other->getUuid().'/membership', null, $token)->getStatusCode());
        self::assertArrayHasKey('roles', $this->data($this->request('GET', '/api/v1/app/users/me', null, $token)));

        $base = '/api/v1/store/'.$store->getUuid();
        self::assertSame(401, $this->request('GET', $base.'/products')->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/v1/store/'.$other->getUuid().'/products', null, $token)->getStatusCode());
        $category = $this->data($this->request('POST', $base.'/product-categories', ['name' => 'Kitchen', 'slug' => 'kitchen', 'sortOrder' => 2, 'enabled' => true], $token));
        $product = $this->data($this->request('POST', $base.'/products', ['name' => 'Contract product', 'status' => 'active', 'category' => $category['uuid']], $token));
        self::assertSame($category['uuid'], $product['category']['uuid']);
        $specPath = $base.'/products/'.$product['uuid'].'/specifications';
        $spec = $this->data($this->request('POST', $specPath, ['name' => 'Regular', 'price' => 1250, 'status' => 'active', 'sort' => 0], $token));
        self::assertSame(1250, $spec['price']);
        $this->data($this->request('PUT', $specPath.'/'.$spec['uuid'], ['price' => 1290], $token));
        $updated = $this->data($this->request('PUT', $base.'/products/'.$product['uuid'], ['name' => 'Updated product', 'status' => 'inactive', 'category' => null], $token));
        self::assertSame('Updated product', $updated['name']);
        self::assertNull($updated['category']);
        foreach (['products', 'product-categories', 'products/'.$product['uuid'].'/specifications', 'counters', 'orders'] as $resource) {
            $response = $this->json($this->request('GET', $base.'/'.$resource.'?page=1&limit=30', null, $token));
            self::assertSame(0, $response['code']);
            self::assertIsArray($response['data']);
            self::assertSame(1, $response['paginator']['page']);
            self::assertFalse($response['paginator']['has_next']);
        }
        $counter = $this->data($this->request('POST', $base.'/counters', ['code' => 'A01', 'name' => 'Table A01', 'capacity' => 4, 'sort' => 0, 'enabled' => true], $token));
        self::assertSame(4, $counter['capacity']);
        $this->data($this->request('PUT', $base.'/counters/'.$counter['uuid'], ['name' => 'Table renamed'], $token));
        $detail = $this->data($this->request('GET', $base.'/orders/'.$order->getUuid(), null, $token));
        self::assertSame($order->getTradeOrderUuid(), $detail['tradeOrderUuid']);
        self::assertIsString($detail['createdAt']);
        self::assertSame(1250, $detail['totalAmount']);
        foreach ([$specPath.'/'.$spec['uuid'], $base.'/products/'.$product['uuid'], $base.'/product-categories/'.$category['uuid'], $base.'/counters/'.$counter['uuid']] as $path) {
            self::assertSame(204, $this->request('DELETE', $path, null, $token)->getStatusCode());
        }
        $refreshed = $this->json($this->request('POST', '/api/auth/token/refresh', ['refresh_token' => $login['refresh_token']]));
        self::assertArrayHasKey('access_token', $refreshed);
        self::assertSame(204, $this->request('POST', '/api/auth/logout', ['refresh_token' => $refreshed['refresh_token']], $refreshed['access_token'])->getStatusCode());
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null, ?string $token = null): Response
    {
        return self::$kernel->handle(Request::create($path, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json', ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]),
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array { return $this->json($response)['data']; }
}
