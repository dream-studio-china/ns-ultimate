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
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\ProductPrintingMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\SpecificationMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\StoreMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\StorePrintingMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\TagMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Service\AdminCrudControllerBridge;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Common\Service\TagWriteService;
use NsUltimate\Business\Counter\Service\CounterWriteService;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Common\Entity\Content;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Store\Entity\Product as BusinessProduct;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Validator\Validation;

$kernel = new Kernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer();
    $em = $container->get('doctrine')->getManager();
    $bridge = $container->get(AdminCrudControllerBridge::class);
    // Legacy preview exporter: explicit test-only synthetic manifest so every
    // previously approved preview case remains exercisable independently of the
    // current composed configuration. The actual catalog export uses the real DI policy.
    $policy = \NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::legacyPolicy();
    // No HTTP request exists in this opt-in exporter. Install the same trusted
    // main-request and authenticated admin token an ordinary admin request would
    // carry so the audited standard-controller bridge and class-level IsGranted run.
    $bridge->installHarnessContext(
        Request::create('http://localhost/api/v1/manage/agent/operations', 'POST'),
        (new \App\Identity\Entity\User())->setRoles(['ROLE_ADMIN']),
    );
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
    $buildService = static function (EntityManagerInterface $manager) use ($validator, $auth, $bridge, $policy): AgentOperationService {
        return new AgentOperationService($manager, new MutationAdapterRegistry([
            new ContentMutationAdapter($manager, $validator, $auth),
            new CounterMutationAdapter($manager, $auth, new CounterWriteService($manager), $bridge),
            new StoreMutationAdapter($manager, $auth),
            new CategoryMutationAdapter($manager, $auth),
            new TagMutationAdapter($manager, $auth, new TagWriteService($manager), $bridge),
            new ProductCategoryMutationAdapter($manager, $auth),
            new ProductMutationAdapter($manager, $validator, $auth),
            new SpecificationMutationAdapter($manager, $auth),
            new StorePrintingMutationAdapter($manager, $auth),
            new ProductPrintingMutationAdapter($manager, $validator, $auth),
        ]), $policy);
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

    // Printing configuration uses the real app-owned adapters and only the
    // disposable SQLite database. No provider service is wired, and the
    // synthetic account cipher is never decrypted or used for a provider call.
    $printingStore = new Store('contract-printing-store', 'Contract Printing Store');
    $printingAccount = (new PrinterAccount(
        'Contract synthetic account',
        'contract-synthetic-developer',
        'contract-synthetic-cipher-not-a-credential',
    ))->setEnabled(true);
    $printingDevice = (new PrinterDevice(
        $printingAccount,
        'Contract receipt device',
        'contract-synthetic-serial',
        PrinterDevice::KIND_RECEIPT,
        $printingStore->getUuid(),
    ))->setEnabled(true);
    $printingTemplate = (new PrinterTemplate('Contract receipt template', PrinterDevice::KIND_RECEIPT))->setEnabled(true);
    $printingStore->setSettings([
        'paymentRate' => 'contract-private-payment-rate',
        'order' => ['requireAcceptance' => true, 'requireInventory' => false],
        'fulfillment' => ['requireVerification' => true],
        'customRoot' => ['secret' => 'contract-private-custom-root'],
        'printing' => [
            'order' => ['enabled' => false, 'deviceUuid' => $printingDevice->getUuid(), 'templateUuid' => $printingTemplate->getUuid(), 'copies' => 2, 'legacyNote' => 'contract-private-printing-note'],
            'perItem' => ['enabled' => true, 'deviceUuid' => $printingDevice->getUuid(), 'templateUuid' => $printingTemplate->getUuid(), 'copies' => 2],
            'filteredList' => ['enabled' => true, 'deviceUuid' => $printingDevice->getUuid(), 'templateUuid' => $printingTemplate->getUuid(), 'copies' => 3],
            'unknownCase' => ['note' => 'contract-private-unknown-case'],
        ],
    ]);
    $printingProduct = (new BusinessProduct($printingStore))
        ->setName('Contract printing product')
        ->setSettings([
            'kitchenPrinterEnable' => false,
            'startSaleTime' => '08:15',
            'endSaleTime' => '21:45',
            'customProductNote' => 'contract-private-product-note',
        ]);
    $em->persist($printingStore);
    $em->persist($printingAccount);
    $em->persist($printingDevice);
    $em->persist($printingTemplate);
    $em->persist($printingProduct);
    $em->flush();

    $printingStoreId = (string) $printingStore->getId();
    $printingProductId = (string) $printingProduct->getId();
    $printingDeviceUuid = $printingDevice->getUuid();
    $printingTemplateUuid = $printingTemplate->getUuid();
    $jobBaseline = $em->getRepository(PrinterJob::class)->count([]);
    $requestBaseline = $em->getRepository(PrinterOrderRequest::class)->count([]);

    // Enabled-only partial patch on an existing case with valid stored refs,
    // plus two new enabled cases. Every difference is a real state change.
    $storePrintingPartial = $prepare('StorePrinting', 'update', $printingStoreId, [
        'order' => ['enabled' => true],
        'fullList' => ['enabled' => true, 'deviceUuid' => $printingDeviceUuid, 'templateUuid' => $printingTemplateUuid, 'copies' => 4],
        'settlement' => ['enabled' => true, 'deviceUuid' => $printingDeviceUuid, 'templateUuid' => $printingTemplateUuid, 'copies' => 5],
    ]);
    $complete($storePrintingPartial);

    // Copies-only patch on an already enabled case, then an explicit
    // disable-and-clear patch for another enabled case.
    $storePrintingVariations = $prepare('StorePrinting', 'update', $printingStoreId, [
        'perItem' => ['copies' => 3],
        'filteredList' => ['enabled' => false, 'deviceUuid' => null, 'templateUuid' => null],
    ]);
    $complete($storePrintingVariations);

    $productPrinting = $prepare('ProductPrinting', 'update', $printingProductId, ['kitchenPrinterEnable' => true]);
    $complete($productPrinting);

    $fixtureAssert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };

    $em->clear();
    $storedPrintingStore = $em->find(Store::class, (int) $printingStoreId);
    if (!$storedPrintingStore instanceof Store) throw new RuntimeException('Printing Store fixture disappeared.');
    $storedSettings = $storedPrintingStore->getSettings() ?? [];
    $fixtureAssert(($storedSettings['paymentRate'] ?? null) === 'contract-private-payment-rate', 'Store printing update did not preserve paymentRate.');
    $fixtureAssert(($storedSettings['order'] ?? null) == ['requireAcceptance' => true, 'requireInventory' => false], 'Store printing update did not preserve order settings.');
    $fixtureAssert(($storedSettings['fulfillment'] ?? null) == ['requireVerification' => true], 'Store printing update did not preserve fulfillment settings.');
    $fixtureAssert(($storedSettings['customRoot']['secret'] ?? null) === 'contract-private-custom-root', 'Store printing update did not preserve customRoot.');
    $fixtureAssert(($storedSettings['printing']['unknownCase']['note'] ?? null) === 'contract-private-unknown-case', 'Store printing update did not preserve an unknown printing case.');
    $fixtureAssert(($storedSettings['printing']['order']['legacyNote'] ?? null) === 'contract-private-printing-note', 'Store printing update did not preserve an unknown stored case key.');
    $fixtureAssert(($storedSettings['printing']['order']['enabled'] ?? null) === true, 'Store printing order case was not enabled.');
    $fixtureAssert(($storedSettings['printing']['order']['deviceUuid'] ?? null) === $printingDeviceUuid, 'Store printing order device reference changed unexpectedly.');
    $fixtureAssert(($storedSettings['printing']['order']['templateUuid'] ?? null) === $printingTemplateUuid, 'Store printing order template reference changed unexpectedly.');
    $fixtureAssert(($storedSettings['printing']['order']['copies'] ?? null) === 2, 'Store printing order copies changed unexpectedly.');
    $fixtureAssert(($storedSettings['printing']['perItem']['copies'] ?? null) === 3, 'Store printing perItem copies were not updated.');
    $fixtureAssert(($storedSettings['printing']['perItem']['enabled'] ?? null) === true, 'Store printing perItem case lost its enabled state.');
    $fixtureAssert(($storedSettings['printing']['filteredList']['enabled'] ?? null) === false, 'Store printing filteredList case was not disabled.');
    $fixtureAssert(array_key_exists('deviceUuid', $storedSettings['printing']['filteredList']) && $storedSettings['printing']['filteredList']['deviceUuid'] === null, 'Store printing filteredList device reference was not cleared.');
    $fixtureAssert(array_key_exists('templateUuid', $storedSettings['printing']['filteredList']) && $storedSettings['printing']['filteredList']['templateUuid'] === null, 'Store printing filteredList template reference was not cleared.');
    $fixtureAssert(($storedSettings['printing']['filteredList']['copies'] ?? null) === 3, 'Store printing filteredList copies changed unexpectedly.');
    foreach (['fullList' => 4, 'settlement' => 5] as $caseKey => $copies) {
        $fixtureAssert(($storedSettings['printing'][$caseKey]['enabled'] ?? null) === true, 'Store printing '.$caseKey.' case was not enabled.');
        $fixtureAssert(($storedSettings['printing'][$caseKey]['deviceUuid'] ?? null) === $printingDeviceUuid, 'Store printing '.$caseKey.' device reference missing.');
        $fixtureAssert(($storedSettings['printing'][$caseKey]['templateUuid'] ?? null) === $printingTemplateUuid, 'Store printing '.$caseKey.' template reference missing.');
        $fixtureAssert(($storedSettings['printing'][$caseKey]['copies'] ?? null) === $copies, 'Store printing '.$caseKey.' copies mismatch.');
    }

    $storedPrintingProduct = $em->find(BusinessProduct::class, (int) $printingProductId);
    if (!$storedPrintingProduct instanceof BusinessProduct) throw new RuntimeException('Printing Product fixture disappeared.');
    $storedProductSettings = $storedPrintingProduct->getSettings() ?? [];
    $fixtureAssert(($storedProductSettings['kitchenPrinterEnable'] ?? null) === true, 'Product kitchenPrinterEnable was not enabled.');
    $fixtureAssert(($storedProductSettings['startSaleTime'] ?? null) === '08:15', 'Product startSaleTime was not preserved.');
    $fixtureAssert(($storedProductSettings['endSaleTime'] ?? null) === '21:45', 'Product endSaleTime was not preserved.');
    $fixtureAssert(($storedProductSettings['customProductNote'] ?? null) === 'contract-private-product-note', 'Product custom setting was not preserved.');
    $fixtureAssert($em->getRepository(PrinterJob::class)->count([]) === $jobBaseline, 'Printing configuration created a PrinterJob row.');
    $fixtureAssert($em->getRepository(PrinterOrderRequest::class)->count([]) === $requestBaseline, 'Printing configuration created a PrinterOrderRequest row.');

    $json = json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach ([
        'contract-private-payment-rate',
        'contract-private-custom-root',
        'contract-private-printing-note',
        'contract-private-unknown-case',
        'contract-private-product-note',
        'contract-synthetic-cipher-not-a-credential',
    ] as $privateValue) {
        $fixtureAssert(!str_contains($json, $privateValue), 'Preview fixture leaked private value: '.$privateValue);
    }
    if (strlen($json) > 4 * 1024 * 1024) throw new RuntimeException('Preview fixture output exceeded its 4 MiB bound.');
    fwrite(STDOUT, $json);
} catch (Throwable $error) {
    fwrite(STDERR, 'Preview fixture export failed: '.get_class($error).' '.$error->getMessage());
    exit(1);
} finally {
    if (isset($policyPath)) @unlink($policyPath);
    $kernel->shutdown();
}

/**
 * Contract fixtures exercise the transport/domain path, not the in-flight admin
 * manifest integration. Build a disposable permissive manifest for exactly the
 * entities/fields this fixture submits, with no nested-path proof (server rules
 * still narrow nested values).
 *
 * @return array{0: AdminConfigurationPolicy, 1: string}
 */
function nsAdminAgentFixturePolicy(): array
{
    $fields = [
        'Content' => ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'],
        'Counter' => ['storeUuid', 'code', 'name', 'capacity', 'sort', 'metadata', 'enabled'],
        'Store' => ['code', 'name', 'status', 'timezone', 'contact', 'address', 'settings', 'paymentSetting'],
        'Category' => ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'],
        'Tag' => ['name', 'slug', 'color'],
        'ProductCategory' => ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled', 'store'],
        'Product' => ['name', 'description', 'category', 'store', 'sortOrder', 'recommendationLevel', 'isSoldout', 'status', 'metadata', 'settings'],
        'Specification' => ['productId', 'name', 'price', 'status', 'sort'],
        'StorePrinting' => ['order', 'fullList', 'perItem', 'filteredList', 'settlement', 'payment'],
        'ProductPrinting' => ['kitchenPrinterEnable'],
    ];
    $entities = [];
    foreach ($fields as $name => $properties) {
        $actions = [];
        foreach (['create', 'update'] as $action) {
            $actions[$action] = [
                'configured' => true,
                'fields' => array_map(static fn (string $property): array => ['property' => $property], $properties),
            ];
        }
        $actions['delete'] = ['configured' => true, 'fields' => []];
        $entities[] = ['key' => $name, 'name' => $name, 'actions' => $actions];
    }
    $payload = [
        'schemaVersion' => AdminConfigurationPolicy::SCHEMA_VERSION,
        'source' => AdminConfigurationPolicy::SOURCE,
        'entities' => $entities,
        'semanticCapabilities' => [],
    ];
    $revision = hash('sha256', json_encode(
        nsAdminAgentFixtureCanonicalize($payload),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
    $path = tempnam(sys_get_temp_dir(), 'ns-agent-fixture-policy-');
    if ($path === false) throw new RuntimeException('Unable to create a disposable policy manifest.');
    file_put_contents($path, json_encode(
        ['revision' => $revision, ...$payload],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));

    return [new AdminConfigurationPolicy($path), $path];
}

function nsAdminAgentFixtureCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map(nsAdminAgentFixtureCanonicalize(...), $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = nsAdminAgentFixtureCanonicalize($item);

    return $value;
}
