<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilityCatalog;
use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilitySchemas;
use NsUltimate\Apps\AdminAgent\Backend\Contract\BatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchCreateAdapterRegistry;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Common\Service\TagWriteService;
use NsUltimate\Business\Counter\Service\CounterWriteService;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CapabilityCatalogTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const URL = '/api/v1/manage/agent/capabilities';

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testEndpointRequiresFullAuthenticationAndAdminRole(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $reader = (new User())->setEmail('catalog-reader@example.test')->setUsername('catalog-reader')->setRoles(['ROLE_USER']);
        $em->persist($reader);
        $em->flush();
        $token = $client->getContainer()->get(TokenManager::class)->createAccessToken($reader);

        $client->request('GET', self::URL, server: ['HTTP_AUTHORIZATION' => '']);
        self::assertResponseStatusCodeSame(401);

        $client->request('GET', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testResponseIsBoundedNoStoreMetadataForActualCapabilities(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertLessThanOrEqual(CapabilityCatalog::MAX_RESPONSE_BYTES, strlen((string) $client->getResponse()->getContent()));

        $catalog = $this->decode($client);
        self::assertSame(['schemaVersion', 'revision', 'capabilities', 'batchCapabilities'], array_keys($catalog));
        self::assertSame(CapabilityCatalog::SCHEMA_VERSION, $catalog['schemaVersion']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $catalog['revision']);
        self::assertSame(
            ['Content', 'Counter', 'Store', 'Category', 'ProductCategory', 'Tag', 'Product', 'Specification', 'StorePrinting', 'ProductPrinting'],
            array_column($catalog['capabilities'], 'entity'),
        );

        $expectedActions = [
            'Content' => ['create', 'update', 'delete'],
            'Counter' => ['create', 'update', 'delete'],
            'Store' => ['update'],
            'Category' => ['create', 'update', 'delete'],
            'ProductCategory' => ['create', 'update', 'delete'],
            'Tag' => ['create', 'update', 'delete'],
            'Product' => ['create', 'update'],
            'Specification' => ['create', 'update'],
            'StorePrinting' => ['update'],
            'ProductPrinting' => ['update'],
        ];

        $covered = [];
        foreach ($catalog['capabilities'] as $capability) {
            $entity = $capability['entity'];
            self::assertSame($expectedActions[$entity], $capability['actions'], $entity.' actions changed');
            self::assertSame(array_keys($capability['actionFields']), array_values(array_intersect($capability['actions'], array_keys($capability['actionFields']))), $entity.' actionFields/actions mismatch');
            foreach ($capability['actionFields'] as $action => $fields) {
                foreach ($fields as $field => $rule) {
                    self::assertIsString($field);
                    self::assertIsArray($rule);
                    self::assertArrayHasKey('type', $rule, $entity.':'.$action.':'.$field.' lacks a type');
                    $covered[$entity.':'.$action.':'.$field] = true;
                }
            }
            $union = [];
            foreach ($capability['actionFields'] as $fields) {
                $union = array_replace($union, $fields);
            }
            self::assertSame($union, $capability['fields'], $entity.' fields must be the action field union');
            self::assertArrayNotHasKey('delete', $capability['actionFields'], $entity.' actionFields must contain write actions only');
            if (in_array('delete', $capability['actions'], true)) {
                self::assertArrayHasKey('deletePreviewFields', $capability, $entity.' must expose safe delete preview metadata');
                self::assertSame(array_keys($capability['fields']), array_keys($capability['deletePreviewFields']), $entity.' delete preview fields must use the existing safe policy');
            } else {
                self::assertArrayNotHasKey('deletePreviewFields', $capability, $entity.' has no authorized delete action');
            }
        }

        self::assertArrayHasKey('Content:create:title', $covered);
        self::assertArrayHasKey('Counter:create:storeUuid', $covered);
        self::assertArrayHasKey('Store:update:address', $covered);
        self::assertArrayHasKey('Category:create:parent', $covered);
        self::assertArrayHasKey('ProductCategory:create:store', $covered);
        self::assertArrayHasKey('Tag:create:color', $covered);
        self::assertArrayHasKey('Product:create:store', $covered);
        self::assertArrayHasKey('Specification:create:price', $covered);
        self::assertArrayHasKey('StorePrinting:update:settlement', $covered);
        self::assertArrayHasKey('ProductPrinting:update:kitchenPrinterEnable', $covered);

        self::assertSame(
            ['Counter', 'Tag'],
            array_column($catalog['batchCapabilities'], 'entity'),
        );
        $singleByEntity = array_column($catalog['capabilities'], null, 'entity');
        foreach ($catalog['batchCapabilities'] as $batch) {
            self::assertSame(['create'], $batch['actions']);
            self::assertSame(['create'], array_keys($batch['actionFields']));
            self::assertSame($singleByEntity[$batch['entity']]['actionFields']['create'], $batch['actionFields']['create']);
            self::assertSame($batch['actionFields']['create'], $batch['fields']);
            self::assertArrayNotHasKey('deletePreviewFields', $batch, 'Batch descriptors must not contain delete preview metadata.');
        }
        self::assertGreaterThanOrEqual(1, CapabilityCatalog::BATCH_MIN_ROWS);
        self::assertSame(20, CapabilityCatalog::BATCH_MAX_ROWS);
    }

    public function testStaticDescriptorsKeepTheApprovedFieldBoundaries(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', self::URL);
        $byEntity = array_column($this->decode($client)['capabilities'], null, 'entity');

        $content = $byEntity['Content']['actionFields']['create'];
        self::assertSame(['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'], array_keys($content));
        self::assertTrue($content['title']['requiredOnCreate']);
        self::assertTrue($content['title']['nonEmpty']);
        self::assertSame(20000, $content['body']['maxBytes']);
        self::assertSame(50, $content['tags']['maxItems']);
        self::assertSame(['featured', 'layout'], array_keys($content['metadata']['properties']));

        $store = $byEntity['Store']['actionFields']['update'];
        self::assertSame(['name', 'contact', 'address'], array_keys($store));
        self::assertSame(['latitude', 'longitude'], $store['address']['pairedProperties'][0]);
        self::assertArrayNotHasKey('settings', $store);

        $category = $byEntity['Category']['actionFields']['create'];
        self::assertSame(255, $category['slug']['maxLength']);
        self::assertSame(['integer', 'numeric-id-string', 'string'], array_column($category['parent']['anyOf'], 'type'));

        $productCategoryCreate = $byEntity['ProductCategory']['actionFields']['create'];
        $productCategoryUpdate = $byEntity['ProductCategory']['actionFields']['update'];
        self::assertSame(100, $productCategoryCreate['slug']['maxLength']);
        self::assertNull($productCategoryCreate['store']['default']);
        self::assertArrayNotHasKey('store', $productCategoryUpdate);
        self::assertTrue($productCategoryCreate['parent']['mustMatchStoreScope']);

        $productCreate = $byEntity['Product']['actionFields']['create'];
        $productUpdate = $byEntity['Product']['actionFields']['update'];
        self::assertTrue($productCreate['name']['requiredOnCreate']);
        self::assertFalse($productUpdate['name']['requiredOnCreate']);
        self::assertArrayHasKey('store', $productCreate);
        self::assertArrayNotHasKey('store', $productUpdate);
        self::assertTrue($productCreate['description']['disallowHtmlTags']);
        self::assertTrue($productCreate['category']['mustBeEnabled']);
        self::assertTrue($productCreate['category']['mustMatchProductStore']);

        $specificationCreate = $byEntity['Specification']['actionFields']['create'];
        $specificationUpdate = $byEntity['Specification']['actionFields']['update'];
        self::assertTrue($specificationCreate['price']['requiredOnCreate']);
        self::assertSame(0, $specificationCreate['price']['minimum']);
        self::assertTrue($specificationCreate['productId']['immutableScope']);
        self::assertTrue($specificationUpdate['productId']['requiredOnUpdate']);
        self::assertTrue($specificationUpdate['productId']['immutableScope']);

        $printing = $byEntity['StorePrinting']['actionFields']['update'];
        self::assertSame(['order', 'perItem', 'filteredList', 'fullList', 'settlement'], array_keys($printing));
        foreach ($printing as $case) {
            self::assertFalse($case['nullable']);
            self::assertSame(['enabled', 'deviceUuid', 'templateUuid', 'copies'], array_keys($case['properties']));
            self::assertSame(100, $case['properties']['copies']['maximum']);
        }
        self::assertSame(
            ['kitchenPrinterEnable'],
            array_keys($byEntity['ProductPrinting']['actionFields']['update']),
        );
    }

    public function testPrintingDescriptionsAreExactAndOtherCapabilitiesDoNotLeakInfrastructureMetadata(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', self::URL);
        $catalog = $this->decode($client);
        $byEntity = array_column($catalog['capabilities'], null, 'entity');

        foreach (['Content', 'Counter', 'Store', 'Category', 'ProductCategory', 'Tag', 'Product', 'Specification'] as $entity) {
            self::assertArrayNotHasKey('description', $byEntity[$entity]);
        }
        self::assertSame(
            'Update the five fixed automatic order-printing cases on the exact stored Store identified by numeric ID or UUID. Only update is supported; settings roots and unrelated settings fields are not accepted.',
            $byEntity['StorePrinting']['description'],
        );
        self::assertSame(
            'Update only kitchenPrinterEnable on the exact stored Product identified by numeric ID or UUID. Only update is supported; arbitrary settings, metadata, and other fields are not accepted.',
            $byEntity['ProductPrinting']['description'],
        );

        $serialized = (string) $client->getResponse()->getContent();
        foreach (['NsUltimate\\', 'Symfony\\', 'Doctrine\\', 'App\\', dirname(__DIR__, 6), 'http://', 'https://', 'apiToken', 'bankAccountNumber'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized, 'Catalog leaked infrastructure or excluded metadata: '.$forbidden);
        }
    }

    public function testRevisionIsTheStableCanonicalHashOfTheDescriptors(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', self::URL);
        $first = $this->decode($client);
        $client->request('GET', self::URL);
        $second = $this->decode($client);
        self::assertSame($first['revision'], $second['revision']);

        $content = ['schemaVersion' => $first['schemaVersion'], 'capabilities' => $first['capabilities'], 'batchCapabilities' => $first['batchCapabilities']];
        $expected = hash('sha256', json_encode($this->canonicalize($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::assertSame($expected, $first['revision']);
    }

    public function testCatalogUsesOnlySelectedAdaptersAndFiltersDeniedActions(): void
    {
        $content = new class implements MutationAdapter {
            public function entityKey(): string { return 'Content'; }
            public function authorize(string $action, ?string $identifier): void
            {
                if ($action === 'delete') throw new AccessDeniedException('Denied.');
            }
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };
        $store = new class implements MutationAdapter {
            public function entityKey(): string { return 'Store'; }
            public function authorize(string $action, ?string $identifier): void
            {
                if ($action !== 'update') throw new AccessDeniedException('Denied.');
            }
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };
        $denied = new class implements MutationAdapter {
            public function entityKey(): string { return 'Category'; }
            public function authorize(string $action, ?string $identifier): void { throw new AccessDeniedException('Denied.'); }
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };

        $catalog = new CapabilityCatalog(
            new MutationAdapterRegistry([$content, $store, $denied]),
            new BatchCreateAdapterRegistry([]),
        );
        $result = $catalog->build();

        self::assertSame(['Content', 'Store'], array_column($result['capabilities'], 'entity'));
        self::assertSame(['create', 'update'], $result['capabilities'][0]['actions']);
        self::assertSame(['create', 'update'], array_keys($result['capabilities'][0]['actionFields']));
        self::assertArrayNotHasKey('deletePreviewFields', $result['capabilities'][0], 'Unauthorized delete must not expose preview metadata.');
        self::assertSame(['update'], $result['capabilities'][1]['actions']);
        self::assertArrayNotHasKey('deletePreviewFields', $result['capabilities'][1]);
        self::assertSame([], $result['batchCapabilities']);
    }

    public function testDeleteOnlyCapabilitySerializesAnEmptyActionFieldObject(): void
    {
        $deleteOnly = new class implements MutationAdapter {
            public function entityKey(): string { return 'Content'; }
            public function authorize(string $action, ?string $identifier): void
            {
                if ($action !== 'delete') throw new AccessDeniedException('Denied.');
            }
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };
        $catalog = new CapabilityCatalog(new MutationAdapterRegistry([$deleteOnly]), new BatchCreateAdapterRegistry([]));
        $result = $catalog->build();

        self::assertSame(['delete'], $result['capabilities'][0]['actions']);
        self::assertInstanceOf(\stdClass::class, $result['capabilities'][0]['actionFields']);
        self::assertInstanceOf(\stdClass::class, $result['capabilities'][0]['fields']);
        self::assertSame(CapabilitySchemas::forEntity('Content')['fields'], $result['capabilities'][0]['deletePreviewFields']);
        self::assertSame(['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'], array_keys($result['capabilities'][0]['deletePreviewFields']));
        self::assertFalse(property_exists($result['capabilities'][0]['actionFields'], 'delete'));
        $encoded = $catalog->encode($result);
        self::assertStringContainsString('"actionFields":{}', $encoded);
        self::assertStringContainsString('"fields":{}', $encoded);
        self::assertStringContainsString('"deletePreviewFields":{', $encoded);
    }

    public function testDeleteOnlyDescriptorIsAcceptedByTheCurrentFrontendCompiler(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec is unavailable; frontend compiler acceptance cannot be checked.');
        }
        $nodeOutput = [];
        $nodeCode = 1;
        exec('command -v node 2>/dev/null', $nodeOutput, $nodeCode);
        $node = trim((string) ($nodeOutput[0] ?? ''));
        $root = dirname(__DIR__, 5);
        $module = $root.'/apps/admin-agent/src/capability-catalog.js';
        if ($nodeCode !== 0 || $node === '' || !is_file($module)) {
            self::markTestSkipped('Node or the frontend capability compiler is unavailable.');
        }

        $deleteOnly = new class implements MutationAdapter {
            public function entityKey(): string { return 'Content'; }
            public function authorize(string $action, ?string $identifier): void
            {
                if ($action !== 'delete') throw new AccessDeniedException('Denied.');
            }
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };
        $catalog = new CapabilityCatalog(new MutationAdapterRegistry([$deleteOnly]), new BatchCreateAdapterRegistry([]));
        $file = tempnam(sys_get_temp_dir(), 'capability-delete-only-');
        self::assertIsString($file);
        file_put_contents($file, $catalog->encode($catalog->build()));

        $moduleUrl = 'file://'.implode('/', array_map('rawurlencode', explode('/', $module)));
        $script = <<<JS
import fs from 'node:fs'
import { compileCapabilityCatalogResponse } from '$moduleUrl'
const payload = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'))
const compiled = compileCapabilityCatalogResponse(payload)
const descriptor = compiled.capabilities[0]
if (!descriptor || descriptor.entity !== 'Content' || descriptor.actions.length !== 1 || descriptor.actions[0] !== 'delete') {
  throw new Error('Expected a delete-only Content capability.')
}
if (Object.keys(descriptor.actionFields).length !== 0 || Object.keys(descriptor.fields).length !== 0) {
  throw new Error('Delete-only write field maps must stay empty.')
}
console.log('accepted')
JS;
        $output = [];
        $code = 1;
        exec(escapeshellarg($node).' --input-type=module -e '.escapeshellarg($script).' '.escapeshellarg($file).' 2>&1', $output, $code);
        @unlink($file);

        self::assertSame(0, $code, implode("\n", $output));
        self::assertSame('accepted', trim((string) ($output[0] ?? '')));
    }

    public function testUpdateOnlyProductDoesNotAdvertiseDeniedCreateFields(): void
    {
        $updateOnly = new class implements MutationAdapter {
            public function entityKey(): string { return 'Product'; }
            public function authorize(string $action, ?string $identifier): void
            {
                if ($action !== 'update') throw new AccessDeniedException('Denied.');
            }
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };
        $catalog = new CapabilityCatalog(new MutationAdapterRegistry([$updateOnly]), new BatchCreateAdapterRegistry([]));
        $result = $catalog->build()['capabilities'][0];

        self::assertSame(['update'], $result['actions']);
        self::assertSame(['update'], array_keys($result['actionFields']));
        self::assertArrayNotHasKey('create', $result['actionFields']);
        self::assertArrayNotHasKey('store', $result['fields'], 'Create-only Product.store must not leak through fields.');
        self::assertArrayNotHasKey('deletePreviewFields', $result);
        self::assertSame($result['actionFields']['update'], $result['fields']);
        self::assertFalse($result['fields']['name']['requiredOnCreate']);
        self::assertStringNotContainsString('"store"', $catalog->encode($catalog->build()));
    }

    public function testBatchCapabilitiesAreDerivedFromSelectedAndAuthorizedBatchAdapters(): void
    {
        self::bootKernel();
        $tagService = self::getContainer()->get(TagWriteService::class);
        $allowed = new class implements BatchCreateAdapter {
            public function entityKey(): string { return 'Tag'; }
            public function authorize(): void {}
            public function prepare(array $rows): array { throw new \LogicException('Not used.'); }
            public function execute(array $rows): array { throw new \LogicException('Not used.'); }
        };
        $denied = new class implements BatchCreateAdapter {
            public function entityKey(): string { return 'Counter'; }
            public function authorize(): void { throw new AccessDeniedException('Denied.'); }
            public function prepare(array $rows): array { throw new \LogicException('Not used.'); }
            public function execute(array $rows): array { throw new \LogicException('Not used.'); }
        };

        $catalog = new CapabilityCatalog(
            new MutationAdapterRegistry([
                $this->permissiveAdapter('Counter'),
                $this->permissiveAdapter('Tag'),
            ]),
            new BatchCreateAdapterRegistry([$allowed, $denied]),
            $tagService,
        );
        $batch = $catalog->build()['batchCapabilities'];

        self::assertCount(1, $batch);
        self::assertSame('Tag', $batch[0]['entity']);
        self::assertSame(['create'], $batch[0]['actions']);
        self::assertSame($batch[0]['actionFields']['create'], $batch[0]['fields']);
        self::assertSame(['name', 'slug', 'color'], array_keys($batch[0]['fields']));
    }

    public function testPilotDescriptorsComeFromBusinessSchemasAndKeepOnlyApprovedFields(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $tag = $container->get(TagWriteService::class);
        $counter = $container->get(CounterWriteService::class);
        self::assertInstanceOf(TagWriteService::class, $tag);
        self::assertInstanceOf(CounterWriteService::class, $counter);

        $registry = new MutationAdapterRegistry([
            $this->permissiveAdapter('Counter'),
            $this->permissiveAdapter('Tag'),
        ]);
        $catalog = new CapabilityCatalog($registry, new BatchCreateAdapterRegistry([]), $tag, $counter);
        $byEntity = array_column($catalog->build()['capabilities'], null, 'entity');

        foreach (['Tag', 'Counter'] as $entity) {
            $service = $entity === 'Tag' ? $tag : $counter;
            foreach (['create', 'update'] as $action) {
                $expected = $service::fieldSchema($action);
                self::assertSame(
                    array_values(array_intersect(array_keys($expected), array_keys($byEntity[$entity]['actionFields'][$action]))),
                    array_keys($byEntity[$entity]['actionFields'][$action]),
                );
                foreach ($byEntity[$entity]['actionFields'][$action] as $field => $rule) {
                    $expectedRule = $expected[$field];
                    $actualRule = $rule;
                    // requiredOnCreate is an exported action-contract flag; all other metadata is
                    // taken verbatim from the business schema.
                    unset($expectedRule['requiredOnCreate'], $actualRule['requiredOnCreate']);
                    self::assertSame($expectedRule, $actualRule, $entity.':'.$action.':'.$field.' must use the business rule');
                }
            }
        }
        self::assertSame(['name', 'slug', 'color'], array_keys($byEntity['Tag']['fields']));
        self::assertSame(['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled'], array_keys($byEntity['Counter']['fields']));
        self::assertArrayNotHasKey('metadata', $byEntity['Counter']['actionFields']['create']);
        self::assertArrayNotHasKey('metadata', $byEntity['Counter']['actionFields']['update']);
    }

    public function testUnknownQueryParametersAreRejectedAndLocaleIsAccepted(): void
    {
        $client = self::createAuthenticatedClient();
        foreach (['?entity=Content', '?fields=title', '?locale=en', '?_locale[]=en'] as $suffix) {
            $client->request('GET', self::URL.$suffix);
            self::assertResponseStatusCodeSame(400);
        }
        foreach (['?_locale=en', '?_locale=zh_Hant'] as $suffix) {
            $client->request('GET', self::URL.$suffix);
            self::assertResponseIsSuccessful();
        }
    }

    /** @return array<string, mixed> */
    private function decode($client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return $body['data'] ?? $body;
    }

    private function permissiveAdapter(string $entity): MutationAdapter
    {
        return new class($entity) implements MutationAdapter {
            public function __construct(private readonly string $entity) {}
            public function entityKey(): string { return $this->entity; }
            public function authorize(string $action, ?string $identifier): void {}
            public function prepare(string $action, ?string $identifier, array $changes): array { throw new \LogicException('Not used.'); }
            public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array { throw new \LogicException('Not used.'); }
        };
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
