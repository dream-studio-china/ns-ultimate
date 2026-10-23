<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AgentOperationServiceTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private ?string $manifestPath = null;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if ($this->manifestPath !== null) {
            @unlink($this->manifestPath);
            $this->manifestPath = null;
        }
    }

    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testOneGenericTableDispatchesMultipleExplicitAdaptersAndPreservesStringIdentifiers(): void
    {
        $first = new RecordingMutationAdapter('Article');
        $second = new RecordingMutationAdapter('Notebook');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = $this->service($em, new MutationAdapterRegistry([$first, $second]));
        foreach (['Article', 'Notebook'] as $entity) {
            $preview = $service->prepare('account-uuid', ['entity' => $entity, 'action' => 'update', 'identifier' => 'external:alpha', 'changes' => ['label' => 'New']]);
            self::assertSame($entity, $preview['entity']);
            self::assertSame('external:alpha', $preview['identifier']);
            self::assertFalse($preview['saved']);
            $receipt = $service->commit('account-uuid', $preview['operationId']);
            self::assertSame('succeeded', $receipt['status']);
            self::assertSame($entity, $receipt['entity']);
            $service->commit('account-uuid', $preview['operationId']);
        }
        self::assertSame(1, $first->executions);
        self::assertSame(1, $second->executions);
        self::assertSame(2, $em->getRepository(AgentOperation::class)->count(['actor' => 'account-uuid']));
    }

    public function testConfigurationPolicyDependencyIsMandatory(): void
    {
        $this->expectException(\ArgumentCountError::class);
        new AgentOperationService(
            self::getContainer()->get(EntityManagerInterface::class),
            new MutationAdapterRegistry([]),
        );
    }

    public function testUnknownEntitiesAreNotResolvedToClassesOrEndpoints(): void
    {
        $service = $this->service(self::getContainer()->get(EntityManagerInterface::class), new MutationAdapterRegistry([]));
        $this->expectException(BadRequestHttpException::class);
        $service->prepare('account-uuid', ['entity' => '\\Arbitrary\\Entity', 'action' => 'create', 'changes' => ['label' => 'No']]);
    }

    public function testDuplicateAdapterKeysFailClosed(): void
    {
        $this->expectException(\LogicException::class);
        new MutationAdapterRegistry([new RecordingMutationAdapter('Article'), new RecordingMutationAdapter('Article')]);
    }

    public function testUnverifiedMutationRollsBackInsteadOfRecordingSuccess(): void
    {
        $adapter = new RecordingMutationAdapter('Article');
        $adapter->verified = false;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = $this->service($em, new MutationAdapterRegistry([$adapter]));
        $preview = $service->prepare('account-uuid', ['entity' => 'Article', 'action' => 'create', 'changes' => ['label' => 'New']]);
        try {
            $service->commit('account-uuid', $preview['operationId']);
            self::fail('Unverified writes must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Mutation read-back verification failed.', $exception->getMessage());
        }
        self::assertSame('pending', $em->getConnection()->fetchOne('SELECT status FROM admin_agent_operation WHERE id = ?', [$preview['operationId']]));
    }

    public function testRecoveryHidesDisabledAdaptersAndRechecksRevokedPermissions(): void
    {
        $adapter = new RecordingMutationAdapter('Article');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = $this->service($em, new MutationAdapterRegistry([$adapter]));
        $preview = $service->prepare('recovery-account', ['entity' => 'Article', 'action' => 'create', 'changes' => ['label' => 'New']]);
        self::assertCount(1, $service->recent('recovery-account')['items']);
        $disabled = $this->service($em, new MutationAdapterRegistry([]));
        self::assertSame([], $disabled->recent('recovery-account')['items']);
        $adapter->authorized = false;
        self::assertSame([], $service->recent('recovery-account')['items']);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $service->restore('recovery-account', $preview['operationId']);
    }

    public function testRecoveryRejectsChangedCanonicalPayloadWithoutExecutingIt(): void
    {
        $adapter = new RecordingMutationAdapter('Article');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = $this->service($em, new MutationAdapterRegistry([$adapter]));
        $preview = $service->prepare('policy-account', ['entity' => 'Article', 'action' => 'create', 'changes' => ['label' => 'New']]);
        $adapter->policyChanged = true;
        try {
            $service->restore('policy-account', $preview['operationId']);
            self::fail('Changed canonical payloads must not be restored.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            self::assertSame(0, $adapter->executions);
        }
    }

    public function testRecoveryIgnoresNestedMapKeyOrderButPreservesListOrderAndScalarTypes(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $adapter = new RecordingMutationAdapter('Article');
        $service = $this->service($em, new MutationAdapterRegistry([$adapter]));
        $changes = ['settings' => ['z' => 2, 'a' => ['right' => true, 'left' => false]], 'sequence' => ['first', 'second'], 'count' => 1];
        $preview = $service->prepare('restore-account', ['entity' => 'Article', 'action' => 'create', 'changes' => $changes]);

        $adapter->preparedChanges = ['settings' => ['a' => ['left' => false, 'right' => true], 'z' => 2], 'sequence' => ['first', 'second'], 'count' => 1];
        $em->getConnection()->executeStatement('UPDATE admin_agent_operation SET changes = ? WHERE id = ?', [
            json_encode($changes, JSON_THROW_ON_ERROR), $preview['operationId'],
        ]);
        $em->clear();
        self::assertSame($preview['operationId'], $service->restore('restore-account', $preview['operationId'])['operationId']);

        $adapter->preparedChanges['sequence'] = ['second', 'first'];
        $this->expectException(\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException::class);
        $service->restore('restore-account', $preview['operationId']);
    }

    public function testRecoveryRejectsScalarTypeChanges(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $adapter = new RecordingMutationAdapter('Article');
        $service = $this->service($em, new MutationAdapterRegistry([$adapter]));
        $preview = $service->prepare('restore-type-account', ['entity' => 'Article', 'action' => 'create', 'changes' => ['count' => 1, 'enabled' => false]]);

        $adapter->preparedChanges = ['count' => '1', 'enabled' => false];
        try {
            $service->restore('restore-type-account', $preview['operationId']);
            self::fail('Scalar type changes must not be restored.');
        } catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) {
            self::assertSame(0, $adapter->executions);
        }
    }

    public function testRecoveryRejectsFalseVersusZero(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $adapter = new RecordingMutationAdapter('Article');
        $service = $this->service($em, new MutationAdapterRegistry([$adapter]));
        $preview = $service->prepare('restore-false-account', ['entity' => 'Article', 'action' => 'create', 'changes' => ['enabled' => false]]);
        $adapter->preparedChanges = ['enabled' => 0];
        $this->expectException(\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException::class);
        $service->restore('restore-false-account', $preview['operationId']);
    }

    public function testConfigurationGateRejectsPrepareAndRevokesPendingProposalButKeepsStatus(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $adapter = new RecordingMutationAdapter('Article');
        $manifest = $this->writeManifest([
            'Article' => $this->manifestEntity('Article', ['update' => ['label']], true),
        ]);
        $service = new AgentOperationService(
            $em,
            new MutationAdapterRegistry([$adapter]),
            new AdminConfigurationPolicy($manifest),
        );

        try {
            $service->prepare('gate-account', [
                'entity' => 'Article',
                'action' => 'update',
                'identifier' => '1',
                'changes' => ['unconfigured' => 'No'],
            ]);
            self::fail('An unconfigured field must be rejected before preparation.');
        } catch (BadRequestHttpException) {
            self::assertSame(0, $adapter->executions);
        }

        $preview = $service->prepare('gate-account', [
            'entity' => 'Article',
            'action' => 'update',
            'identifier' => '1',
            'changes' => ['label' => 'New'],
        ]);

        $this->writeManifest([
            'Article' => $this->manifestEntity('Article', ['update' => ['other']], true),
        ]);

        try {
            $service->commit('gate-account', $preview['operationId']);
            self::fail('A revoked field must become a proposal conflict.');
        } catch (ProposalConflictException) {
            self::assertSame(0, $adapter->executions);
        }

        // A failed commit ends that HTTP request and its EntityManager. Recovery
        // uses a fresh request/manager and must still work.
        self::ensureKernelShutdown();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = new AgentOperationService(
            $em,
            new MutationAdapterRegistry([$adapter]),
            new AdminConfigurationPolicy($manifest),
        );
        self::assertSame('pending', $service->status('gate-account', $preview['operationId'])['status']);

        try {
            $service->restore('gate-account', $preview['operationId']);
            self::fail('A revoked field must not be restorable.');
        } catch (ProposalConflictException) {
            // Expected.
        }

        self::ensureKernelShutdown();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = new AgentOperationService(
            $em,
            new MutationAdapterRegistry([$adapter]),
            new AdminConfigurationPolicy($manifest),
        );
        self::assertSame('cancelled', $service->cancel('gate-account', $preview['operationId'])['status']);
    }

    public function testRemovedNestedLeafIsRejectedAtPrepareAndRevokedAtCommit(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $adapter = new RecordingMutationAdapter('Article');
        $entity = static function (array $nestedPaths): array {
            return [
                'key' => 'Article',
                'name' => 'Article',
                'actions' => [
                    'create' => ['configured' => false, 'fields' => []],
                    'update' => ['configured' => true, 'fields' => [
                        ['property' => 'settings', 'nestedPaths' => $nestedPaths],
                    ]],
                    'delete' => ['configured' => false, 'fields' => []],
                ],
            ];
        };
        $manifest = $this->writeManifest(['Article' => $entity(['settings.allowed'])]);
        $service = new AgentOperationService(
            $em,
            new MutationAdapterRegistry([$adapter]),
            new AdminConfigurationPolicy($manifest),
        );

        try {
            $service->prepare('nested-gate-account', [
                'entity' => 'Article',
                'action' => 'update',
                'identifier' => '1',
                'changes' => ['settings' => ['forbidden' => true]],
            ]);
            self::fail('An unknown nested leaf must be rejected before preparation.');
        } catch (BadRequestHttpException) {
            // Expected.
        }

        $preview = $service->prepare('nested-gate-account', [
            'entity' => 'Article',
            'action' => 'update',
            'identifier' => '1',
            'changes' => ['settings' => ['allowed' => true]],
        ]);
        $this->writeManifest(['Article' => $entity(['settings.other'])]);
        try {
            $service->commit('nested-gate-account', $preview['operationId']);
            self::fail('A removed nested leaf must become a proposal conflict.');
        } catch (ProposalConflictException) {
            self::assertSame(0, $adapter->executions);
        }
    }

    public function testMissingManifestBlocksPrepareButNotExistingStatusOrRecent(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $manifest = $this->writeManifest([
            'Article' => $this->manifestEntity('Article', ['create' => ['label']], false),
        ]);
        $policy = new AdminConfigurationPolicy($manifest);
        $adapter = new RecordingMutationAdapter('Article');
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]), $policy);

        $preview = $service->prepare('missing-manifest-account', [
            'entity' => 'Article',
            'action' => 'create',
            'changes' => ['label' => 'Kept'],
        ]);
        unlink($manifest);

        try {
            $service->prepare('missing-manifest-account', [
                'entity' => 'Article',
                'action' => 'create',
                'changes' => ['label' => 'New'],
            ]);
            self::fail('A missing artifact must fail write preparation closed.');
        } catch (BadRequestHttpException) {
            // Expected.
        }

        self::assertSame('pending', $service->status('missing-manifest-account', $preview['operationId'])['status']);
        self::assertCount(1, $service->recent('missing-manifest-account')['items']);
    }

    private function service(
        EntityManagerInterface $em,
        MutationAdapterRegistry $registry,
        array $entityNames = ['Article', 'Notebook'],
    ): AgentOperationService {
        $entities = [];
        foreach ($entityNames as $name) {
            $entities[$name] = $this->manifestEntity($name, [
                'create' => ['label', 'settings', 'sequence', 'count', 'enabled'],
                'update' => ['label', 'settings', 'sequence', 'count', 'enabled'],
            ], true);
        }

        return new AgentOperationService($em, $registry, new AdminConfigurationPolicy($this->writeManifest($entities)));
    }

    /** @param array<string, array<string, mixed>> $entities */
    private function writeManifest(array $entities): string
    {
        $list = array_values($entities);
        usort($list, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        $payload = [
            'schemaVersion' => AdminConfigurationPolicy::SCHEMA_VERSION,
            'source' => AdminConfigurationPolicy::SOURCE,
            'entities' => $list,
            'semanticCapabilities' => [],
        ];
        $payload['revision'] = $this->revisionOf($payload);
        $this->manifestPath ??= sys_get_temp_dir().'/admin-agent-operation-policy-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($this->manifestPath, json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->manifestPath;
    }

    /**
     * @param array<string, list<string>> $writeFields
     * @return array<string, mixed>
     */
    private function manifestEntity(string $name, array $writeFields, bool $delete): array
    {
        $actions = [];
        foreach (['create', 'update'] as $action) {
            $actions[$action] = [
                'configured' => array_key_exists($action, $writeFields),
                'fields' => array_map(static fn (string $property): array => ['property' => $property], $writeFields[$action] ?? []),
            ];
        }
        $actions['delete'] = ['configured' => $delete, 'fields' => []];

        return ['key' => $name, 'name' => $name, 'actions' => $actions];
    }

    /** @param array<string, mixed> $payload */
    private function revisionOf(array $payload): string
    {
        unset($payload['revision']);
        return hash('sha256', json_encode($this->canonicalizePolicy($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalizePolicy(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalizePolicy(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalizePolicy($item);
        return $value;
    }
}

/** Test fixture, not registered in production DI or enabled as a domain capability. */
final class RecordingMutationAdapter implements MutationAdapter
{
    public int $executions = 0;
    public bool $verified = true;
    public bool $authorized = true;
    public bool $policyChanged = false;
    /** @var array<string, mixed>|null */
    public ?array $preparedChanges = null;
    public function __construct(private readonly string $key) {}
    public function entityKey(): string { return $this->key; }
    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorized) throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
    }
    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        if ($this->preparedChanges !== null) $changes = $this->preparedChanges;
        if ($this->policyChanged) $changes['label'] = 'Changed normalization';
        $property = array_key_first($changes) ?? 'label';
        return ['identifier' => $identifier, 'title' => 'Fixture', 'beforeHash' => str_repeat('a', 64),
            'changes' => $changes, 'differences' => [['property' => $property, 'before' => 'Old', 'after' => $changes[$property] ?? null]]];
    }
    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->executions += 1;
        return ['identifier' => $identifier ?? 'new:record', 'verified' => $this->verified];
    }
}
