<?php

declare(strict_types=1);

// This opt-in fixture exporter runs only against the integration bootstrap's
// randomly named SQLite file. That bootstrap forces APP_ENV=test and owns DB
// cleanup; do not replace it with the application's configured database URL.
// No provider, printing or network service is used. All domain writes happen
// in the disposable SQLite database through the real batch operation service.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require dirname(__DIR__, 5).'/integration/backend/tests/bootstrap.php';

use App\Common\Entity\Tag;
use App\Store\Entity\Store;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\CounterBatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\CounterMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\TagBatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\TagMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentBatchOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Service\AdminCrudControllerBridge;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchAgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchCreateAdapterRegistry;
use NsUltimate\Business\Common\Service\TagWriteService;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Service\CounterWriteService;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

$kernel = new Kernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer();
    $em = $container->get('doctrine')->getManager();
    $bridge = $container->get(AdminCrudControllerBridge::class);
    // Legacy preview exporter: test-only synthetic manifest; the runtime batch
    // gate in production still uses the real generated policy.
    $policy = \NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::legacyPolicy();
    $bridge->installHarnessContext(
        Request::create('http://localhost/api/v1/manage/agent/batch-operations', 'POST'),
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
    $buildService = static fn (EntityManagerInterface $manager): BatchAgentOperationService => new BatchAgentOperationService(
        $manager,
        new BatchCreateAdapterRegistry([
            new TagBatchCreateAdapter(new TagMutationAdapter($manager, $auth, new TagWriteService($manager), $bridge)),
            new CounterBatchCreateAdapter(new CounterMutationAdapter($manager, $auth, new CounterWriteService($manager), $bridge)),
        ]),
        $policy,
    );
    $service = $buildService($em);
    $doctrine = $container->get('doctrine');
    $actor = 'batch-contract-fixture';
    $fixtureAssert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    $count = static fn (EntityManagerInterface $manager, string $entityClass): int => $manager->getRepository($entityClass)->count([]);
    $counts = static fn (EntityManagerInterface $manager): array => ['Tag' => $count($manager, Tag::class), 'Counter' => $count($manager, Counter::class)];

    $store = new Store('batch-contract-store', 'Batch Contract Store');
    $em->persist($store);
    $em->flush();
    $storeUuid = $store->getUuid();

    $assertReceiptHidesValues = static function (array $receipts, array $values): void {
        $serialized = json_encode($receipts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ($values as $value) {
            if (!str_contains($serialized, $value)) continue;
            throw new RuntimeException('Batch receipt leaked a proposal value: '.$value);
        }
    };
    $assertPreviewShape = static function (array $preview, string $entity, int $rowCount) use ($fixtureAssert): void {
        $fixtureAssert(array_keys($preview) === ['operationId', 'entity', 'action', 'title', 'affectedRecords', 'expiresAt', 'rows', 'saved'], 'Batch preview envelope shape changed.');
        $fixtureAssert((bool) preg_match('/^[a-f0-9]{64}$/D', (string) ($preview['operationId'] ?? '')), 'Batch preview operation identifier is invalid.');
        $fixtureAssert(($preview['entity'] ?? null) === $entity, 'Batch preview entity mismatch.');
        $fixtureAssert(($preview['action'] ?? null) === 'create', 'Batch preview action must be create.');
        $fixtureAssert(($preview['saved'] ?? null) === false, 'Batch preview must not be saved.');
        $fixtureAssert(($preview['affectedRecords'] ?? null) === $rowCount, 'Batch preview affectedRecords mismatch.');
        $fixtureAssert(is_string($preview['title'] ?? null) && $preview['title'] !== '', 'Batch preview title is missing.');
        $fixtureAssert(is_string($preview['expiresAt'] ?? null) && strtotime($preview['expiresAt']) !== false, 'Batch preview expiry is invalid.');
        $fixtureAssert(is_array($preview['rows'] ?? null) && array_is_list($preview['rows']) && count($preview['rows']) === $rowCount, 'Batch preview rows are invalid.');
        foreach ($preview['rows'] as $index => $row) {
            $fixtureAssert(array_keys($row) === ['row', 'title', 'identifier', 'differences'], 'Batch preview row shape changed.');
            $fixtureAssert(($row['row'] ?? null) === $index + 1, 'Batch preview row ordering changed.');
            $fixtureAssert(($row['identifier'] ?? null) === null, 'A prepared batch row must not have an identifier.');
            $fixtureAssert(is_string($row['title'] ?? null) && $row['title'] !== '', 'Batch preview row title is missing.');
            $fixtureAssert(is_array($row['differences'] ?? null) && array_is_list($row['differences']) && $row['differences'] !== [], 'Batch preview row differences are invalid.');
            foreach ($row['differences'] as $difference) {
                $fixtureAssert(array_keys($difference) === ['property', 'before', 'after'], 'Batch preview difference shape changed.');
                $fixtureAssert(is_string($difference['property'] ?? null) && array_key_exists('before', $difference) && $difference['before'] === null, 'Batch preview create difference must have a null before value.');
                $fixtureAssert(array_key_exists('after', $difference), 'Batch preview difference is missing its after value.');
            }
        }
    };

    $tagRows = [
        ['name' => 'Contract Batch Tag One', 'slug' => 'contract-batch-tag-one', 'color' => '#A1B2C3'],
        ['name' => 'Contract Batch Tag Two', 'slug' => 'contract-batch-tag-two'],
        ['name' => 'Contract Batch Tag Three', 'slug' => 'contract-batch-tag-three', 'color' => null],
    ];
    $tagInput = ['entity' => 'Tag', 'action' => 'create', 'rows' => $tagRows];
    $tagBefore = $counts($em);
    $tagPrepared = $service->prepare($actor, $tagInput);
    $tagAfterPrepare = $counts($em);
    $tagRestored = $service->restore($actor, $tagPrepared['operationId']);
    $tagAfterRestore = $counts($em);
    $assertPreviewShape($tagPrepared, 'Tag', 3);
    $fixtureAssert($tagPrepared === $tagRestored, 'A prepared Tag batch preview did not restore exactly.');
    $fixtureAssert($tagBefore === $tagAfterPrepare && $tagBefore === $tagAfterRestore, 'Preparing or restoring a Tag batch wrote domain rows.');
    $fixtureAssert($em->find(AgentBatchOperation::class, $tagPrepared['operationId'])?->getStatus() === 'pending', 'A prepared Tag batch was not pending.');
    $tagReceipt = $service->commit($actor, $tagPrepared['operationId']);
    $fixtureAssert(($tagReceipt['verified'] ?? false) === true && ($tagReceipt['status'] ?? null) === 'succeeded', 'A disposable Tag batch failed verification.');
    $em->clear();
    $tagAfterCommit = $counts($em);
    $fixtureAssert($tagAfterCommit['Tag'] === $tagBefore['Tag'] + 3, 'A Tag batch did not create exactly three rows.');
    $fixtureAssert($tagAfterCommit['Counter'] === $tagBefore['Counter'], 'A Tag batch changed Counter rows.');
    $expectedTagValues = [
        ['name' => 'Contract Batch Tag One', 'slug' => 'contract-batch-tag-one', 'color' => '#A1B2C3'],
        ['name' => 'Contract Batch Tag Two', 'slug' => 'contract-batch-tag-two', 'color' => null],
        ['name' => 'Contract Batch Tag Three', 'slug' => 'contract-batch-tag-three', 'color' => null],
    ];
    $tagPersistence = [];
    foreach ($tagReceipt['results'] as $index => $result) {
        $fixtureAssert(($result['row'] ?? null) === $index + 1, 'A Tag receipt result lost row ordering.');
        $tag = $em->find(Tag::class, (int) $result['identifier']);
        $fixtureAssert($tag instanceof Tag, 'A committed Tag row could not be read back.');
        $values = ['name' => $tag->getName(), 'slug' => $tag->getSlug(), 'color' => $tag->getColor()];
        $fixtureAssert($values === $expectedTagValues[$index], 'A committed Tag row does not match its canonical row and defaults.');
        $tagPersistence[] = ['row' => $result['row'], 'identifier' => $result['identifier'], 'found' => true, 'values' => $values];
    }
    $fixtureAssert($tagAfterCommit['Tag'] === $tagBefore['Tag'] + count($tagPersistence), 'A Tag commit created an unexpected row count.');

    $tagReplay = $service->commit($actor, $tagPrepared['operationId']);
    $fixtureAssert(($tagReplay['verified'] ?? false) === true && ($tagReplay['status'] ?? null) === 'succeeded', 'A Tag replay did not return a verified succeeded receipt.');
    $fixtureAssert($tagReplay === $tagReceipt, 'A Tag replay changed the settled receipt schema or values.');
    $em->clear();
    $tagAfterReplay = $counts($em);
    $fixtureAssert($tagAfterReplay === $tagAfterCommit, 'A Tag replay changed domain rows.');
    $tagStatus = $service->status($actor, $tagPrepared['operationId']);
    $fixtureAssert(!array_key_exists('verified', $tagStatus), 'A Tag status receipt unexpectedly exposes the commit verification marker.');
    $tagSettled = $tagReplay;
    unset($tagSettled['verified']);
    $fixtureAssert($tagStatus === $tagSettled, 'A Tag status receipt differs from the settled replay receipt.');
    $assertReceiptHidesValues([$tagReceipt, $tagReplay, $tagStatus], [
        'Contract Batch Tag One', 'Contract Batch Tag Two', 'Contract Batch Tag Three',
        'contract-batch-tag-one', 'contract-batch-tag-two', 'contract-batch-tag-three',
    ]);

    $counterRows = [
        ['storeUuid' => $storeUuid, 'code' => 'BATCH-CONTRACT-1', 'name' => 'Contract Batch Counter One'],
        ['storeUuid' => $storeUuid, 'code' => 'BATCH-CONTRACT-2', 'name' => 'Contract Batch Counter Two', 'capacity' => 4, 'sort' => 2, 'enabled' => true],
        ['storeUuid' => $storeUuid, 'code' => 'BATCH-CONTRACT-3', 'name' => 'Contract Batch Counter Three', 'enabled' => false],
    ];
    $counterInput = ['entity' => 'Counter', 'action' => 'create', 'rows' => $counterRows];
    $counterBefore = $counts($em);
    $counterPrepared = $service->prepare($actor, $counterInput);
    $counterAfterPrepare = $counts($em);
    $counterRestored = $service->restore($actor, $counterPrepared['operationId']);
    $counterAfterRestore = $counts($em);
    $assertPreviewShape($counterPrepared, 'Counter', 3);
    $fixtureAssert($counterPrepared === $counterRestored, 'A prepared Counter batch preview did not restore exactly.');
    $fixtureAssert($counterBefore === $counterAfterPrepare && $counterBefore === $counterAfterRestore, 'Preparing or restoring a Counter batch wrote domain rows.');
    $fixtureAssert($em->find(AgentBatchOperation::class, $counterPrepared['operationId'])?->getStatus() === 'pending', 'A prepared Counter batch was not pending.');
    $counterReceipt = $service->commit($actor, $counterPrepared['operationId']);
    $fixtureAssert(($counterReceipt['verified'] ?? false) === true && ($counterReceipt['status'] ?? null) === 'succeeded', 'A disposable Counter batch failed verification.');
    $em->clear();
    $counterAfterCommit = $counts($em);
    $fixtureAssert($counterAfterCommit['Counter'] === $counterBefore['Counter'] + 3, 'A Counter batch did not create exactly three rows.');
    $fixtureAssert($counterAfterCommit['Tag'] === $counterBefore['Tag'], 'A Counter batch changed Tag rows.');
    $expectedCounterValues = [
        ['storeUuid' => $storeUuid, 'code' => 'BATCH-CONTRACT-1', 'name' => 'Contract Batch Counter One', 'capacity' => null, 'sort' => 0, 'enabled' => true],
        ['storeUuid' => $storeUuid, 'code' => 'BATCH-CONTRACT-2', 'name' => 'Contract Batch Counter Two', 'capacity' => 4, 'sort' => 2, 'enabled' => true],
        ['storeUuid' => $storeUuid, 'code' => 'BATCH-CONTRACT-3', 'name' => 'Contract Batch Counter Three', 'capacity' => null, 'sort' => 0, 'enabled' => false],
    ];
    $counterPersistence = [];
    foreach ($counterReceipt['results'] as $index => $result) {
        $fixtureAssert(($result['row'] ?? null) === $index + 1, 'A Counter receipt result lost row ordering.');
        $counter = $em->find(Counter::class, (int) $result['identifier']);
        $fixtureAssert($counter instanceof Counter, 'A committed Counter row could not be read back.');
        $values = [
            'storeUuid' => $counter->getStoreUuid(), 'code' => $counter->getCode(), 'name' => $counter->getName(),
            'capacity' => $counter->getCapacity(), 'sort' => $counter->getSort(), 'enabled' => $counter->getEnabled(),
        ];
        $fixtureAssert($values === $expectedCounterValues[$index], 'A committed Counter row does not match its canonical row and defaults.');
        $counterPersistence[] = ['row' => $result['row'], 'identifier' => $result['identifier'], 'found' => true, 'values' => $values];
    }
    $fixtureAssert($counterAfterCommit['Counter'] === $counterBefore['Counter'] + count($counterPersistence), 'A Counter commit created an unexpected row count.');

    $counterReplay = $service->commit($actor, $counterPrepared['operationId']);
    $fixtureAssert(($counterReplay['verified'] ?? false) === true && ($counterReplay['status'] ?? null) === 'succeeded', 'A Counter replay did not return a verified succeeded receipt.');
    $fixtureAssert($counterReplay === $counterReceipt, 'A Counter replay changed the settled receipt schema or values.');
    $em->clear();
    $counterAfterReplay = $counts($em);
    $fixtureAssert($counterAfterReplay === $counterAfterCommit, 'A Counter replay changed domain rows.');
    $counterStatus = $service->status($actor, $counterPrepared['operationId']);
    $fixtureAssert(!array_key_exists('verified', $counterStatus), 'A Counter status receipt unexpectedly exposes the commit verification marker.');
    $counterSettled = $counterReplay;
    unset($counterSettled['verified']);
    $fixtureAssert($counterStatus === $counterSettled, 'A Counter status receipt differs from the settled replay receipt.');
    $assertReceiptHidesValues([$counterReceipt, $counterReplay, $counterStatus], [
        'Contract Batch Counter One', 'Contract Batch Counter Two', 'Contract Batch Counter Three',
        'BATCH-CONTRACT-1', 'BATCH-CONTRACT-2', 'BATCH-CONTRACT-3',
    ]);

    // One row is claimed concurrently after preview. The whole batch must roll
    // back, leave the operation pending with its rows intact, and create no
    // proposal rows at all.
    $rollbackRows = [
        ['name' => 'Contract Rollback One', 'slug' => 'contract-batch-rollback-one'],
        ['name' => 'Contract Rollback Two', 'slug' => 'contract-batch-rollback-two'],
        ['name' => 'Contract Rollback Three', 'slug' => 'contract-batch-rollback-three'],
    ];
    $rollbackInput = ['entity' => 'Tag', 'action' => 'create', 'rows' => $rollbackRows];
    $rollbackPreview = $service->prepare($actor, $rollbackInput);
    $rollbackBefore = $counts($em);
    $em->persist(new Tag('Contract Rollback Claim', 'contract-batch-rollback-three'));
    $em->flush();
    $rollbackRejected = false;
    try {
        $service->commit($actor, $rollbackPreview['operationId']);
    } catch (ProposalConflictException) {
        $rollbackRejected = true;
    }
    // Doctrine closes the manager after a rejected transactional commit in
    // this configuration; rebuild it before reading the rolled-back state.
    $doctrine->resetManager();
    $em = $doctrine->getManager();
    $service = $buildService($em);
    $rollbackOperation = $em->find(AgentBatchOperation::class, $rollbackPreview['operationId']);
    if (!$rollbackOperation instanceof AgentBatchOperation) throw new RuntimeException('Rollback batch operation disappeared.');
    $rollbackAfter = $counts($em);
    $proposalRowCounts = [];
    foreach ($rollbackRows as $row) {
        // Export counts only; proposal labels, slugs and values must not leave
        // the operation receipt boundary.
        $proposalRowCounts[] = $em->getRepository(Tag::class)->count(['name' => $row['name']]);
    }
    $fixtureAssert($rollbackRejected, 'A concurrently claimed Tag row did not reject the whole batch.');
    $fixtureAssert(array_sum($proposalRowCounts) === 0, 'A rolled-back Tag batch left proposal rows behind.');
    $fixtureAssert($rollbackAfter['Tag'] === $rollbackBefore['Tag'] + 1, 'Rollback changed Tag rows beyond the external claim.');
    $fixtureAssert($rollbackAfter['Counter'] === $rollbackBefore['Counter'], 'Rollback changed Counter rows.');
    $fixtureAssert($rollbackOperation->getStatus() === 'pending' && count($rollbackOperation->getRows()) === 3, 'A rolled-back batch did not remain pending with its rows.');
    $rollbackReceipt = $rollbackOperation->receipt();
    $assertReceiptHidesValues([$rollbackReceipt], [
        'Contract Rollback One', 'Contract Rollback Two', 'Contract Rollback Three',
        'contract-batch-rollback-one', 'contract-batch-rollback-two', 'contract-batch-rollback-three',
    ]);

    // Cancellation must erase proposal values and create no domain rows.
    $cancelRows = [
        ['name' => 'Contract Cancel One', 'slug' => 'contract-batch-cancel-one'],
        ['name' => 'Contract Cancel Two', 'slug' => 'contract-batch-cancel-two'],
    ];
    $cancelInput = ['entity' => 'Tag', 'action' => 'create', 'rows' => $cancelRows];
    $cancelPreview = $service->prepare($actor, $cancelInput);
    $cancelReceipt = $service->cancel($actor, $cancelPreview['operationId']);
    $em->clear();
    $cancelOperation = $em->find(AgentBatchOperation::class, $cancelPreview['operationId']);
    $fixtureAssert($cancelOperation instanceof AgentBatchOperation, 'Cancelled batch operation disappeared.');
    $fixtureAssert(($cancelReceipt['status'] ?? null) === 'cancelled' && ($cancelReceipt['results'] ?? null) === [], 'A cancelled batch receipt is invalid.');
    $fixtureAssert($cancelOperation->getRows() === [], 'A cancelled batch did not erase its proposal rows.');
    foreach ($cancelRows as $row) {
        $fixtureAssert($em->getRepository(Tag::class)->count(['slug' => $row['slug']]) === 0, 'A cancelled batch created a domain row.');
    }
    $assertReceiptHidesValues([$cancelReceipt], [
        'Contract Cancel One', 'Contract Cancel Two',
        'contract-batch-cancel-one', 'contract-batch-cancel-two',
    ]);

    $recent = $service->recent($actor);
    $fixtureAssert(is_array($recent['items'] ?? null) && count($recent['items']) >= 4, 'Recent batch metadata is incomplete.');
    $recentSerialized = json_encode($recent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach ([
        'Contract Batch Tag One', 'contract-batch-tag-one',
        'Contract Batch Counter One', 'BATCH-CONTRACT-1',
        'Contract Rollback One', 'contract-batch-rollback-one',
        'Contract Cancel One', 'contract-batch-cancel-one',
    ] as $value) {
        $fixtureAssert(!str_contains($recentSerialized, $value), 'Recent batch metadata leaked a proposal value: '.$value);
    }

    $cases = [
        [
            'entity' => 'Tag', 'input' => $tagInput,
            'prepared' => $tagPrepared, 'restored' => $tagRestored,
            'receipt' => $tagReceipt, 'replay' => $tagReplay, 'status' => $tagStatus,
            'persistence' => [
                'counts' => ['beforePrepare' => $tagBefore, 'afterPrepare' => $tagAfterPrepare, 'afterRestore' => $tagAfterRestore, 'afterCommit' => $tagAfterCommit, 'afterReplay' => $tagAfterReplay],
                'rows' => $tagPersistence,
            ],
        ],
        [
            'entity' => 'Counter', 'input' => $counterInput,
            'prepared' => $counterPrepared, 'restored' => $counterRestored,
            'receipt' => $counterReceipt, 'replay' => $counterReplay, 'status' => $counterStatus,
            'persistence' => [
                'counts' => ['beforePrepare' => $counterBefore, 'afterPrepare' => $counterAfterPrepare, 'afterRestore' => $counterAfterRestore, 'afterCommit' => $counterAfterCommit, 'afterReplay' => $counterAfterReplay],
                'rows' => $counterPersistence,
            ],
        ],
    ];
    $entities = array_values(array_unique(array_column(array_column($cases, 'prepared'), 'entity')));
    sort($entities);
    $fixtureAssert($entities === ['Counter', 'Tag'], 'The fixture must expose only the two known batch entities.');

    $export = [
        'cases' => $cases,
        'rollback' => [
            'input' => $rollbackInput,
            'rejected' => $rollbackRejected,
            'error' => ProposalConflictException::class,
            'proposalRowCounts' => $proposalRowCounts,
            'counts' => ['before' => $rollbackBefore, 'after' => $rollbackAfter],
            'operation' => $rollbackReceipt,
            'rowsRetained' => count($rollbackOperation->getRows()),
        ],
        'cancel' => [
            'input' => $cancelInput,
            'receipt' => $cancelReceipt,
            'rowsErased' => $cancelOperation->getRows() === [],
            'createdRows' => array_sum(array_map(
                static fn (array $row): int => $em->getRepository(Tag::class)->count(['slug' => $row['slug']]),
                $cancelRows,
            )),
        ],
        'recent' => $recent,
    ];
    $json = json_encode($export, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 4 * 1024 * 1024) throw new RuntimeException('Batch preview fixture output exceeded its 4 MiB bound.');
    fwrite(STDOUT, $json);
} catch (Throwable $error) {
    fwrite(STDERR, 'Batch preview fixture export failed: '.get_class($error).' '.$error->getMessage());
    exit(1);
} finally {
    if (isset($policyPath)) @unlink($policyPath);
    $kernel->shutdown();
}

/** @return array{0: AdminConfigurationPolicy, 1: string} */
function nsAdminAgentBatchFixturePolicy(): array
{
    $fields = [
        'Tag' => ['name', 'slug', 'color'],
        'Counter' => ['storeUuid', 'code', 'name', 'capacity', 'sort', 'metadata', 'enabled'],
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
        nsAdminAgentBatchFixtureCanonicalize($payload),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
    $path = tempnam(sys_get_temp_dir(), 'ns-agent-batch-fixture-policy-');
    if ($path === false) throw new RuntimeException('Unable to create a disposable batch policy manifest.');
    file_put_contents($path, json_encode(
        ['revision' => $revision, ...$payload],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));

    return [new AdminConfigurationPolicy($path), $path];
}

function nsAdminAgentBatchFixtureCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map(nsAdminAgentBatchFixtureCanonicalize(...), $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = nsAdminAgentBatchFixtureCanonicalize($item);

    return $value;
}
