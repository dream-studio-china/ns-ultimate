<?php

declare(strict_types=1);

// This opt-in fixture exporter runs only against the integration bootstrap's
// randomly named SQLite file. That bootstrap forces APP_ENV=test and owns DB
// cleanup; do not replace it with the application's configured database URL.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require dirname(__DIR__, 5).'/integration/backend/tests/bootstrap.php';

use App\Common\Entity\Category;
use App\Common\Entity\Tag;
use App\Store\Entity\Product as StoreProduct;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\CategoryMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\ContentMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\CounterMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\ProductCategoryMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\ProductMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\SpecificationMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\StoreMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\TagMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Common\Entity\Content;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Validator\Validation;

$kernel = new Kernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer();
    $em = $container->get('doctrine')->getManager();
    $connection = $em->getConnection();
    $params = $connection->getParams();
    $database = (string) ($params['path'] ?? '');
    if (!$connection->getDatabasePlatform() instanceof SQLitePlatform
        || !str_starts_with($database, sys_get_temp_dir().'/ns-ultimate-integration-')
        || !str_ends_with($database, '.sqlite')) {
        throw new RuntimeException('Refusing schema setup: connection is not the disposable integration SQLite database.');
    }
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

    $auth = new class implements AuthorizationCheckerInterface {
        public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool { return $attribute === 'ROLE_ADMIN'; }
    };
    $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    $buildService = static function (EntityManagerInterface $manager) use ($validator, $auth): AgentOperationService {
        return new AgentOperationService($manager, new MutationAdapterRegistry([
            new ContentMutationAdapter($manager, $validator, $auth),
            new CounterMutationAdapter($manager, $auth),
            new StoreMutationAdapter($manager, $auth),
            new CategoryMutationAdapter($manager, $auth),
            new TagMutationAdapter($manager, $auth),
            new ProductCategoryMutationAdapter($manager, $auth),
            new ProductMutationAdapter($manager, $validator, $auth),
            new SpecificationMutationAdapter($manager, $auth),
        ]));
    };
    $service = $buildService($em);
    $doctrine = $container->get('doctrine');
    $actor = 'contract-fixture';
    $cases = [];
    $record = static function (array $preview, array $input) use (&$cases): array {
        $cases[] = ['preview' => $preview, 'input' => $input];
        return $preview;
    };
    $roundTrip = static function (array $preview) use (&$em, &$service, $doctrine, $buildService, $actor, $record): array {
        $doctrine->resetManager();
        $em = $doctrine->getManager();
        $service = $buildService($em);
        $operation = $em->find(\NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation::class, $preview['operationId']);
        if ($operation === null) throw new RuntimeException('Prepared operation was not persisted.');
        $canonicalChanges = $operation->getChanges();
        return $record($service->restore($actor, $preview['operationId']), [
            'entity' => $preview['entity'], 'action' => $preview['action'],
            ...($preview['identifier'] === null ? [] : ['identifier' => $preview['identifier']]),
            // Service restore consumes persisted canonical changes. Export the
            // actual normalized request from AgentOperation after preparation.
            'changes' => $canonicalChanges,
        ]);
    };
    $prepare = static function (string $entity, string $action, ?string $id, array $changes) use (&$service, $actor, $record): array {
        $input = ['entity' => $entity, 'action' => $action, ...($id === null ? [] : ['identifier' => $id]), 'changes' => $changes];
        return $record($service->prepare($actor, $input), $input);
    };
    $complete = static function (array $preview) use ($roundTrip, &$service, &$em, $actor): array {
        $roundTrip($preview);
        $receipt = $service->commit($actor, $preview['operationId']);
        if (($receipt['verified'] ?? false) !== true) throw new RuntimeException('A disposable fixture mutation failed verification.');
        $em->clear();
        return $receipt;
    };
    $crud = static function (string $entity, array $create, array $update) use ($prepare, $complete): void {
        $created = $complete($prepare($entity, 'create', null, $create));
        $updated = $complete($prepare($entity, 'update', (string) $created['identifier'], $update));
        $complete($prepare($entity, 'delete', (string) $updated['identifier'], []));
    };

    $category = new Category('Fixture content category', 'contract-content-category');
    $tag = new Tag('Fixture content tag', 'contract-content-tag');
    $store = (new Store('contract-preview-store', 'Fixture store'))
        ->setContact(['phone' => '13800138000', 'email' => 'private@example.com', 'managerName' => 'Fixture manager'])
        ->setAddress(['city' => 'Private City', 'detail' => 'Private address']);
    $em->persist($category); $em->persist($tag); $em->persist($store); $em->flush();
    $categoryId = $category->getId(); $tagId = $tag->getId(); $storeUuid = $store->getUuid(); $storeId = $store->getId();

    $crud('Content', ['title' => 'Fixture Content', 'body' => 'Initial body', 'category' => $categoryId, 'tags' => [$tagId]], ['title' => 'Updated Content']);
    $crud('Counter', ['storeUuid' => $storeUuid, 'code' => 'contract-counter', 'name' => 'Fixture Counter'], ['name' => 'Updated Counter']);
    $storeInput = ['entity' => 'Store', 'action' => 'update', 'identifier' => (string) $storeId,
        'changes' => ['name' => 'Updated fixture store', 'contact' => ['phone' => '13900139000'], 'address' => ['city' => 'New City']]];
    $storePreview = $record($service->prepare($actor, $storeInput), $storeInput);
    $complete($storePreview);
    $clearAddressInput = ['entity' => 'Store', 'action' => 'update', 'identifier' => (string) $storeId, 'changes' => ['address' => null]];
    $complete($record($service->prepare($actor, $clearAddressInput), $clearAddressInput));
    // Explicitly cover a partial object whose untouched sibling exists only in
    // storage; previews must redact the before object and never disclose it.
    $clearInput = ['entity' => 'Store', 'action' => 'update', 'identifier' => (string) $storeId, 'changes' => ['contact' => null]];
    $complete($record($service->prepare($actor, $clearInput), $clearInput));
    $crud('Category', ['name' => 'Fixture Category', 'slug' => 'contract-category'], ['name' => 'Updated Category']);
    $crud('Tag', ['name' => 'Fixture Tag', 'slug' => 'contract-tag', 'color' => '#123abc'], ['name' => 'Updated Tag']);

    foreach ([null, $storeId] as $scope) {
        $suffix = $scope === null ? 'global' : 'store';
        $create = ['name' => 'Fixture Product Category '.$suffix, 'slug' => 'contract-product-category-'.$suffix, 'store' => $scope];
        $created = $complete($prepare('ProductCategory', 'create', null, $create));
        $updated = $complete($prepare('ProductCategory', 'update', (string) $created['identifier'], ['name' => 'Updated Product Category '.$suffix]));
        $complete($prepare('ProductCategory', 'delete', (string) $updated['identifier'], []));
    }

    $longTitle = 'Product #5 size '.str_repeat('界', 255 - mb_strlen('Product #5 size '));
    $productCreate = ['name' => $longTitle, 'description' => 'Fixture product'];
    $globalProduct = $complete($prepare('Product', 'create', null, $productCreate));
    $complete($prepare('Product', 'update', (string) $globalProduct['identifier'], ['name' => 'Updated global product']));
    $storeProduct = $complete($prepare('Product', 'create', null, ['name' => 'Store fixture product', 'store' => $storeUuid]));
    $complete($prepare('Product', 'update', (string) $storeProduct['identifier'], ['name' => 'Updated store product']));
    $em->clear();
    $specProduct = $em->find(StoreProduct::class, (int) $storeProduct['identifier']);
    if (!$specProduct instanceof StoreProduct) throw new RuntimeException('Specification parent product fixture missing.');
    $specCreate = ['productId' => $specProduct->getId(), 'name' => $longTitle, 'price' => 2147483647];
    $spec = $complete($prepare('Specification', 'create', null, $specCreate));
    $updatedLongTitle = 'Product #5 size '.str_repeat('界', 255 - mb_strlen('Product #5 size ') - 1).'更';
    $complete($prepare('Specification', 'update', (string) $spec['identifier'], ['productId' => $specProduct->getId(), 'name' => $updatedLongTitle]));

    $json = json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 4 * 1024 * 1024) throw new RuntimeException('Preview fixture output exceeded its 4 MiB bound.');
    fwrite(STDOUT, $json);
} catch (Throwable $error) {
    fwrite(STDERR, 'Preview fixture export failed: '.get_class($error).' '.$error->getMessage());
    exit(1);
} finally {
    $kernel->shutdown();
}
