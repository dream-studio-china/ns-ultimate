<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Trade\Service\Catalog\CatalogResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Business\Store\Entity\StaffOperation;
use NsUltimate\Business\Store\Service\ProductAvailability;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StaffOperationsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $token;
    private Store $store;
    private Store $other;
    private Product $product;
    private Role $role;
    private User $user;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->user = (new User())->setUsername('staff-operations')->setEmail('staff-operations@example.test');
        $this->store = new Store('staff-operations', 'Staff operations', 'Asia/Shanghai');
        $this->other = new Store('staff-other', 'Other store');
        $this->role = new Role('staff-operations', 'Staff operations', Role::SCOPE_STORE);
        foreach (['product:read', 'product:create', 'product:update', 'product:availability', 'product_category:read', 'counter:read', 'order:read', 'order:accept', 'order:reject', 'order:fulfill', 'order:verify', 'operation:read', 'catalog_image:create'] as $name) {
            [$resource, $action] = explode(':', $name);
            $permission = new Permission('store:'.$name, 'store', $resource, $action, $name);
            $this->role->addPermission($permission);
            $this->em->persist($permission);
        }
        $this->product = (new Product($this->store))->setName('Original product');
        $this->product->setMetadata(['subtitle' => 'Existing subtitle', 'preserved' => 'existing-metadata']);
        $this->product->setSettings(['preserved' => true]);
        foreach ([$this->user, $this->store, $this->other, $this->role, $this->product,
            new Membership($this->store, $this->user->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($this->role, $this->user->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid())] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($this->user);
    }

    public function testCatalogCommandsAreScopedVersionedAuditedAndIdempotent(): void
    {
        $product = $this->products()[0];
        $body = ['commandUuid' => UUID::v4(), 'version' => $product['version'], 'name' => 'Edited product', 'isSoldout' => true, 'sortOrder' => 3, 'subtitle' => 'New subtitle'];
        $path = '/products/'.$product['uuid'];
        $saved = $this->data($this->request('PUT', $path, $body));
        self::assertTrue($saved['isSoldout']);
        self::assertSame('Edited product', $saved['name']);
        self::assertSame(3, $saved['sortOrder']);
        self::assertSame($saved, $this->data($this->request('PUT', $path, $body)));
        self::assertSame(1, $this->em->getRepository(StaffOperation::class)->count([]));
        self::assertSame(409, $this->request('PUT', $path, [...$body, 'name' => 'Changed retry'])->getStatusCode());
        self::assertSame(409, $this->request('PUT', $path, [...$body, 'commandUuid' => UUID::v4()])->getStatusCode());
        $fresh = $this->products()[0];
        self::assertSame($saved['version'], $fresh['version']);
        self::assertSame(200, $this->request('PUT', $path, ['commandUuid' => UUID::v4(), 'version' => $fresh['version'], 'description' => 'Details'])->getStatusCode());
        $loaded = $this->em->getRepository(Product::class)->findOneBy(['uuid' => $product['uuid']]);
        self::assertSame('existing-metadata', $loaded->getMetadata()['preserved']);
        self::assertTrue($loaded->getSettings()['preserved']);
        self::assertSame(403, $this->request('GET', '/products', null, $this->other->getUuid())->getStatusCode());
        self::assertSame(404, $this->request('PUT', '/products/'.UUID::v4(), [...$body, 'commandUuid' => UUID::v4()])->getStatusCode());
        self::assertSame(401, $this->request('GET', '/products', null, null, false)->getStatusCode());
    }

    public function testSearchIsServerSideBoundedAndCannotOverrideTheStore(): void
    {
        for ($index = 0; $index < 33; $index++) $this->em->persist((new Product($this->store))->setName('Item '.$index));
        $this->em->persist((new Product($this->other))->setName('Other secret'));
        $this->em->persist((new Product($this->store))->setName('Literal 100%'));
        $this->em->flush();
        $result = $this->data($this->request('GET', '/products?page=1&limit=30'));
        self::assertTrue($result['hasNext']);
        self::assertSame(35, $result['total']);
        $search = $this->data($this->request('GET', '/products?q=Item%2032'));
        self::assertSame(['Item 32'], array_column($search['items'], 'name'));
        self::assertSame(1, $this->data($this->request('GET', '/products?q=%25'))['total']);
        self::assertSame(0, $this->data($this->request('GET', '/products?q=secret'))['total']);
        self::assertSame(422, $this->request('GET', '/products?limit=100000')->getStatusCode());
        self::assertSame(422, $this->request('GET', '/products?status=invalid')->getStatusCode());
        self::assertStringContainsString('no-store', $this->request('GET', '/products')->headers->get('Cache-Control'));
    }

    public function testBatchIsAtomicAndAvailabilityHasItsOwnPermission(): void
    {
        $product = $this->products()[0];
        $body = ['commandUuid' => UUID::v4(), 'items' => [['uuid' => $product['uuid'], 'version' => $product['version']], ['uuid' => UUID::v4(), 'version' => 'invalid']], 'isSoldout' => true];
        self::assertSame(404, $this->request('POST', '/products/availability', $body)->getStatusCode());
        self::assertFalse($this->products()[0]['isSoldout']);
        $body['items'] = [$body['items'][0]];
        self::assertSame(422, $this->request('POST', '/products/availability', [...$body, 'items' => array_fill(0, 31, $body['items'][0])])->getStatusCode());
        self::assertSame(200, $this->request('POST', '/products/availability', $body)->getStatusCode());
        self::assertTrue($this->products()[0]['isSoldout']);
        // Revoke the role: an active membership alone is insufficient.
        $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request('POST', '/products/availability', [...$body, 'commandUuid' => UUID::v4()])->getStatusCode());
    }

    public function testInvalidFieldsAndCrossStoreAssociationsAreRejected(): void
    {
        $product = $this->products()[0];
        $category = new ProductCategory();
        $category->setName('Other category')->setSlug('other-category')->setStore($this->other);
        $this->em->persist($category);
        $global = (new ProductCategory())->setName('Global category')->setSlug('global-category');
        $local = (new ProductCategory())->setName('Local category')->setSlug('local-category')->setStore($this->store);
        $this->em->persist($global);
        $this->em->persist($local);
        $this->em->flush();
        self::assertSame(['Local category'], array_column($this->data($this->request('GET', '/product-categories'))['items'], 'name'));
        foreach ([['store' => $this->other->getUuid()], ['metadata' => ['arbitrary' => true]], ['status' => null], ['category' => 123], ['category' => $category->getUuid()], ['isSoldout' => 'yes'], ['sortOrder' => -1], ['startSaleTime' => '25:00', 'endSaleTime' => '26:00'], ['startSaleTime' => '09:00'], ['coverImageUuid' => UUID::v4()]] as $extra) {
            self::assertSame(422, $this->request('PUT', '/products/'.$product['uuid'], ['commandUuid' => UUID::v4(), 'version' => $product['version']] + $extra)->getStatusCode(), json_encode($extra));
        }
        self::assertSame(0, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testOrderLifecycleCommandsRequireReasonPermissionVersionAndPreserveIdempotency(): void
    {
        $order = new StoreOrder($this->store, UUID::v4(), $this->store->getCode(), 'Store', UUID::v4(), 'CNY', 1230, ['items' => [['productName' => 'Soup', 'quantity' => 1]], 'delivery' => ['secret' => 'private']], true);
        $this->em->persist($order);
        $this->em->flush();
        $path = '/orders/'.$order->getUuid();
        $detail = $this->data($this->request('GET', $path));
        self::assertSame(['accept', 'reject'], $detail['allowedActions']);
        self::assertArrayNotHasKey('delivery', $detail['orderSnapshot']);
        self::assertArrayNotHasKey('customerUserUuid', $detail);
        self::assertSame(422, $this->request('POST', $path.'/reject', ['commandUuid' => UUID::v4(), 'version' => $detail['version']])->getStatusCode());
        $command = ['commandUuid' => UUID::v4(), 'version' => $detail['version']];
        $accepted = $this->data($this->request('POST', $path.'/accept', $command));
        self::assertSame('accepted', $accepted['operationalStatus']);
        self::assertSame($accepted, $this->data($this->request('POST', $path.'/accept', $command)));
        self::assertSame(409, $this->request('POST', $path.'/reject', [...$command, 'commandUuid' => UUID::v4(), 'reason' => 'Too late'])->getStatusCode());
        $fulfilled = $this->data($this->request('POST', $path.'/fulfill', ['commandUuid' => UUID::v4(), 'version' => $accepted['version']]));
        self::assertSame(['verify'], $fulfilled['allowedActions']);
        $verified = $this->data($this->request('POST', $path.'/verify', ['commandUuid' => UUID::v4(), 'version' => $fulfilled['version']]));
        self::assertSame('verified', $verified['operationalStatus']);
        self::assertSame([], $verified['allowedActions']);
        self::assertSame(3, $this->em->getRepository(StaffOperation::class)->count([]));
    }

    public function testAvailabilityIsEnforcedByTheComposedPricingPort(): void
    {
        $spec = (new Specification())->setName('Regular')->setPrice(1230)->setProduct($this->product);
        $this->em->persist($spec);
        $this->em->flush();
        $resolver = self::getContainer()->get(CatalogResolverInterface::class);
        self::assertNotNull($resolver->resolveForPricing($spec->getUuid(), $this->store->getCode()));
        $this->product->setIsSoldout(true);
        self::assertNull($resolver->resolveForPricing($spec->getUuid(), $this->store->getCode()));
        $this->product->setIsSoldout(false)->setSettings(['startSaleTime' => '22:00', 'endSaleTime' => '02:00']);
        $availability = new ProductAvailability();
        self::assertTrue($availability->isAvailable($this->product, new \DateTimeImmutable('2026-10-30T22:00:00+08:00')));
        self::assertFalse($availability->isAvailable($this->product, new \DateTimeImmutable('2026-10-31T02:00:00+08:00')));
        self::assertFalse($availability->isAvailable($this->product, new \DateTimeImmutable('2026-10-30T12:00:00+08:00')));
    }

    public function testSeedIsRepeatableAndNeverGrantsUsersOrGlobalRoles(): void
    {
        $command = self::getContainer()->get(\NsUltimate\Business\Store\Command\SeedStaffAuthorizationCommand::class);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        $count = $this->em->getRepository(Permission::class)->count([]);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($count, $this->em->getRepository(Permission::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Assignment::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Membership::class)->count([]));
        self::assertSame(1, $this->em->getRepository(User::class)->count([]));
        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => 'store_order_operator']);
        self::assertSame(Role::SCOPE_STORE, $role->getScopeType());
        self::assertTrue($role->hasPermission('store:order:reject'));
        self::assertFalse($role->hasPermission('store:order:refund'));
        $reader = $this->em->getRepository(Role::class)->findOneBy(['code' => 'store_order_reader']);
        self::assertInstanceOf(Role::class, $reader);
        self::assertSame(Role::SCOPE_STORE, $reader->getScopeType());
        self::assertTrue($reader->hasPermission('store:order:read'));
        self::assertFalse($reader->hasPermission('store:order:accept'));
        self::assertFalse($reader->hasPermission('store:order:fulfill'));
        $availability = $this->em->getRepository(Role::class)->findOneBy(['code' => 'store_availability_operator']);
        self::assertInstanceOf(Role::class, $availability);
        self::assertTrue($availability->hasPermission('store:product:read'));
        self::assertTrue($availability->hasPermission('store:product:availability'));
        self::assertFalse($availability->hasPermission('store:product:update'));
        self::assertFalse($availability->hasPermission('store:product:create'));
        // Job titles belong to the frontend; the backend only registers capability roles.
        foreach (['store_kitchen', 'store_cashier', 'store_dining_operator', 'store_dining_editor'] as $code) {
            self::assertNull($this->em->getRepository(Role::class)->findOneBy(['code' => $code]));
        }
    }

    public function testCapabilityRolesEnforceStoreBoundariesWithoutCrossModuleWrites(): void
    {
        $tester = new CommandTester(self::getContainer()->get(\NsUltimate\Business\Store\Command\SeedStaffAuthorizationCommand::class));
        self::assertSame(0, $tester->execute([]));
        $this->em->persist(new Membership($this->other, $this->user->getUuid(), Membership::ROLE_MANAGER));
        $this->em->flush();
        foreach (['store_availability_operator', 'store_order_reader'] as $code) {
            $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
            $role = $this->em->getRepository(Role::class)->findOneBy(['code' => $code]);
            self::assertInstanceOf(Role::class, $role);
            $this->em->persist(new Assignment($role, $this->user->getUuid(), Assignment::SCOPE_STORE, $this->store->getUuid()));
            $this->em->flush();
            self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
            $availability = $code === 'store_availability_operator';
            self::assertSame($availability ? 200 : 403, $this->request('GET', '/products')->getStatusCode());
            self::assertSame($availability ? 403 : 200, $this->request('GET', '/orders')->getStatusCode());
            self::assertSame(403, $this->request('GET', '/products', null, $this->other->getUuid())->getStatusCode());
            self::assertSame(403, $this->request('GET', '/orders', null, $this->other->getUuid())->getStatusCode());
            self::assertSame(403, $this->request('POST', '/products', ['commandUuid' => UUID::v4(), 'name' => 'Forbidden'])->getStatusCode());
            self::assertSame(403, $this->request('PUT', '/products/'.$this->product->getUuid(), ['commandUuid' => UUID::v4(), 'name' => 'Forbidden'])->getStatusCode());
            foreach (['accept', 'reject', 'fulfill', 'verify'] as $action) {
                self::assertSame(403, $this->request('POST', '/orders/'.UUID::v4().'/'.$action, ['commandUuid' => UUID::v4()])->getStatusCode());
            }
            $counter = self::$kernel->handle(Request::create('/api/v1/store/'.$this->store->getUuid().'/counter-operations/counters', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]));
            self::assertSame(403, $counter->getStatusCode());
            if ($availability) {
                $product = $this->products()[0];
                self::assertSame(200, $this->request('POST', '/products/availability', ['commandUuid' => UUID::v4(), 'items' => [['uuid' => $product['uuid'], 'version' => $product['version']]], 'isSoldout' => true])->getStatusCode());
            }
        }
    }

    public function testStaffSeedScopeConflictFailsWithoutCreatingOtherSeedData(): void
    {
        $this->em->persist(new Role('store_order_reader', 'Conflicting global role', Role::SCOPE_GLOBAL));
        $this->em->flush();
        $count = $this->em->getRepository(Permission::class)->count([]);
        $tester = new CommandTester(self::getContainer()->get(\NsUltimate\Business\Store\Command\SeedStaffAuthorizationCommand::class));
        self::assertSame(1, $tester->execute([]));
        self::assertSame($count, $this->em->getRepository(Permission::class)->count([]));
        self::assertNull($this->em->getRepository(Role::class)->findOneBy(['code' => 'store_availability_operator']));
    }

    public function testCreationAndHistoryDoNotDuplicateOrExposeOtherStores(): void
    {
        $body = ['commandUuid' => UUID::v4(), 'name' => 'New product', 'description' => 'Details', 'startSaleTime' => '22:00', 'endSaleTime' => '02:00'];
        $created = $this->data($this->request('POST', '/products', $body));
        self::assertTrue($created['extensionEditable']);
        self::assertSame($created, $this->data($this->request('POST', '/products', $body)));
        self::assertSame(2, $this->em->getRepository(Product::class)->count([]));
        $history = $this->data($this->request('GET', '/history'));
        self::assertSame(1, $history['total']);
        self::assertSame($this->user->getUuid(), $history['items'][0]['actorUuid']);
        self::assertSame('product.create', $history['items'][0]['action']);
        self::assertArrayNotHasKey('before', $history['items'][0]);
        self::assertArrayNotHasKey('result', $history['items'][0]);
        self::assertSame(403, $this->request('GET', '/history', null, $this->other->getUuid())->getStatusCode());
    }

    public function testCommandResultIsActorScopedRestrictedAndRequiresCurrentAuthorization(): void
    {
        $command = UUID::v4();
        $id = UUID::v4();
        $this->em->persist(new StaffOperation($this->store->getUuid(), $this->user->getUuid(), $command, 'product.create', str_repeat('a', 64), ['secret' => 'before'], ['uuid' => $id, 'secret' => 'private', 'orderUuid' => ['unsafe']]));
        $otherCommand = UUID::v4();
        $this->em->persist(new StaffOperation($this->store->getUuid(), UUID::v4(), $otherCommand, 'product.create', str_repeat('a', 64), [], ['uuid' => UUID::v4()]));
        $unknownAction = UUID::v4();
        $this->em->persist(new StaffOperation($this->store->getUuid(), $this->user->getUuid(), $unknownAction, 'product.delete', str_repeat('a', 64), [], []));
        $this->em->flush();
        $response = $this->request('GET', '/commands/'.$command);
        self::assertSame(['status' => 'succeeded', 'result' => ['uuid' => $id]], $this->data($response));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame(['status' => 'unknown'], $this->data($this->request('GET', '/commands/'.$otherCommand)));
        self::assertSame(['status' => 'unknown'], $this->data($this->request('GET', '/commands/'.UUID::v4())));
        self::assertSame(403, $this->request('GET', '/commands/'.$unknownAction)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/commands/'.$command, null, $this->other->getUuid())->getStatusCode());
        $this->em->getConnection()->executeStatement('DELETE FROM authorization_assignment');
        self::getContainer()->get('cache.app')->delete('authorization_effective_'.$this->user->getUuid());
        self::assertSame(403, $this->request('GET', '/commands/'.$command)->getStatusCode());
        $membership = $this->em->getRepository(Membership::class)->findOneBy(['store' => $this->store, 'userUuid' => $this->user->getUuid()]);
        $membership->suspend();
        $this->em->flush();
        self::assertSame(403, $this->request('GET', '/commands/'.UUID::v4())->getStatusCode());
    }

    public function testCommandResultChecksPermissionForEachRecordedAction(): void
    {
        $cases = [
            'product.create' => true, 'product.availability' => true, 'catalog_image.upload' => true,
            'product.update.'.UUID::v4() => true, 'order.accept.'.UUID::v4() => true,
            'counter_session.update.'.UUID::v4() => false, 'counter_session.end.'.UUID::v4() => false,
            'counter_order.create.'.UUID::v4() => false, 'counter_order.replace.'.UUID::v4() => false,
            'counter_order.cancel.'.UUID::v4() => false, 'counter_order.merge.'.UUID::v4() => false,
            'order.refund.'.UUID::v4() => false,
        ];
        foreach ($cases as $action => $allowed) {
            $command = UUID::v4();
            $this->em->persist(new StaffOperation($this->store->getUuid(), $this->user->getUuid(), $command, $action, str_repeat('a', 64), [], []));
            $this->em->flush();
            self::assertSame($allowed ? 200 : 403, $this->request('GET', '/commands/'.$command)->getStatusCode(), $action);
        }

    }

    public function testMergeReceiptRequiresCurrentMergePermission(): void
    {
        $permission = new Permission('store:counter_order:merge', 'store', 'counter_order', 'merge', 'Merge counter orders');
        $this->em->persist($permission);
        $this->role->addPermission($permission);
        $command = UUID::v4();
        $orderUuid = UUID::v4();
        $this->em->persist(new StaffOperation($this->store->getUuid(), $this->user->getUuid(), $command, 'counter_order.merge.'.UUID::v4(), str_repeat('a', 64), [], ['orderUuid' => $orderUuid]));
        $this->em->flush();
        self::assertSame(['status' => 'succeeded', 'result' => ['orderUuid' => $orderUuid]], $this->data($this->request('GET', '/commands/'.$command)));
    }

    public function testCoreOnlyRowsRemainVisibleWithoutSilentlyAddingExtensionFields(): void
    {
        $core = (new \App\Store\Entity\Product($this->store))->setName('Core-only item');
        $this->em->persist($core);
        $this->em->flush();
        $rows = $this->data($this->request('GET', '/products?q=Core-only'))['items'];
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['extensionEditable']);
        $body = ['commandUuid' => UUID::v4(), 'version' => $rows[0]['version'], 'name' => 'Updated core item'];
        $saved = $this->data($this->request('PUT', '/products/'.$core->getUuid(), $body));
        self::assertSame('Updated core item', $saved['name']);
        self::assertSame(409, $this->request('PUT', '/products/'.$core->getUuid(), ['commandUuid' => UUID::v4(), 'version' => $saved['version'], 'isSoldout' => true])->getStatusCode());
    }

    public function testStoreOwnedImageUploadUsesValidatedRasterFilesAndRejectsCrossStoreReferences(): void
    {
        $mediaService = $this->createMock(\App\Common\Service\MediaServiceInterface::class);
        $mediaService->expects(self::once())->method('createFromUpload')->willReturnCallback(function ($file, $storage, $meta, $owner) {
            self::assertNull($owner);
            self::assertSame('local', $storage);
            $media = new \App\Common\Entity\Media('test.png', 'test.png', 'image/png', 68, '/uploads/staff-image.png');
            $this->em->persist($media);
            $this->em->flush();
            return $media;
        });
        self::getContainer()->set(\App\Common\Service\MediaServiceInterface::class, $mediaService);
        $path = tempnam(sys_get_temp_dir(), 'staff-image-');
        self::assertIsString($path);
        try {
            file_put_contents($path, '<script>not an image</script>');
            $invalid = self::$kernel->handle(Request::create('/api/v1/store/'.$this->store->getUuid().'/operations/images', 'POST', ['commandUuid' => UUID::v4()], [], [
                'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'fake.png', 'image/png', null, true),
            ], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]));
            self::assertSame(422, $invalid->getStatusCode());
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='));
            $command = UUID::v4();
            $upload = function () use ($path, $command): Response {
                return self::$kernel->handle(Request::create('/api/v1/store/'.$this->store->getUuid().'/operations/images', 'POST', ['commandUuid' => $command], [], [
                    'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'test.png', 'image/png', null, true),
                ], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]));
            };
            $image = $this->data($upload());
            self::assertSame($image, $this->data($upload()));
            self::assertSame('/uploads/staff-image.png', $image['url']);
            $product = $this->products()[0];
            $saved = $this->data($this->request('PUT', '/products/'.$product['uuid'], ['commandUuid' => UUID::v4(), 'version' => $product['version'], 'coverImageUuid' => $image['uuid']]));
            self::assertSame($image['url'], $saved['metadata']['cover']);
            $otherImage = new \App\Common\Entity\Media('other.png', 'other.png', 'image/png', 68, '/uploads/other.png');
            $this->em->persist($otherImage);
            $this->em->persist(new \NsUltimate\Business\Store\Entity\CatalogImage($this->other->getUuid(), $otherImage));
            $this->em->flush();
            // Arbitrary/cross-store IDs cannot be used as product covers.
            self::assertSame(422, $this->request('PUT', '/products/'.$product['uuid'], ['commandUuid' => UUID::v4(), 'version' => $saved['version'], 'coverImageUuid' => $otherImage->getUuid()])->getStatusCode());
        } finally { unlink($path); }
    }

    public function testMigrationCreatesAndDropsOnlyOperationalTablesOnSqlite(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261031090000.php';
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP TABLE store_catalog_image');
        $connection->executeStatement('DROP TABLE store_staff_operation');
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration = new \NsUltimate\Business\Store\Migrations\Version20261031090000($connection, new \Psr\Log\NullLogger());
        $migration->up($after);
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)) as $sql) $connection->executeStatement($sql);
        self::assertTrue($manager->tablesExist(['store_catalog_image', 'store_staff_operation']));
        self::assertSame(1, $this->em->getRepository(Product::class)->count([]));
        $down = clone $after;
        $migration->down($down);
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($after, $down)) as $sql) $connection->executeStatement($sql);
        self::assertFalse($manager->tablesExist(['store_catalog_image']));
        self::assertTrue($manager->tablesExist(['trade_product']));
    }

    public function testGenericMediaRemovalCannotDeleteStoreCatalogImagesBeforeDatabaseChecks(): void
    {
        $protected = new \App\Common\Entity\Media('protected.png', 'protected.png', 'image/png', 68, '/uploads/nonexistent-protected.png');
        $ordinary = new \App\Common\Entity\Media('ordinary.png', 'ordinary.png', 'image/png', 68, '/uploads/nonexistent-ordinary.png');
        $this->em->persist($protected);
        $this->em->persist($ordinary);
        $this->em->persist(new \NsUltimate\Business\Store\Entity\CatalogImage($this->store->getUuid(), $protected));
        $this->em->flush();
        $media = self::getContainer()->get(\App\Common\Service\MediaServiceInterface::class);
        self::assertInstanceOf(\NsUltimate\Business\Store\Adapter\CatalogMediaService::class, $media);
        self::assertFalse($media->remove($protected));
        $inner = $this->createMock(\App\Common\Service\MediaServiceInterface::class);
        $inner->expects(self::once())->method('get')->with($ordinary)->willReturn($ordinary);
        $inner->expects(self::once())->method('remove')->with($ordinary)->willReturn(true);
        self::assertTrue((new \NsUltimate\Business\Store\Adapter\CatalogMediaService($inner, $this->em))->remove($ordinary));
        self::assertNotNull($this->em->getRepository(\App\Common\Entity\Media::class)->find($protected->getId()));
    }

    /** @return list<array<string, mixed>> */
    private function products(): array { return $this->data($this->request('GET', '/products'))['items']; }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null, ?string $storeUuid = null, bool $authenticated = true): Response
    {
        return self::$kernel->handle(Request::create('/api/v1/store/'.($storeUuid ?? $this->store->getUuid()).'/operations'.$path, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json', ...($authenticated ? ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token] : []),
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
