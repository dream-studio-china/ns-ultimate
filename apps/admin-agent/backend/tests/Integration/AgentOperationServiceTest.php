<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AgentOperationServiceTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
    }

    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testOneGenericTableDispatchesMultipleExplicitAdaptersAndPreservesStringIdentifiers(): void
    {
        $first = new RecordingMutationAdapter('Article');
        $second = new RecordingMutationAdapter('Notebook');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$first, $second]));
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

    public function testUnknownEntitiesAreNotResolvedToClassesOrEndpoints(): void
    {
        $service = new AgentOperationService(self::getContainer()->get(EntityManagerInterface::class), new MutationAdapterRegistry([]));
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
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
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
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
        $preview = $service->prepare('recovery-account', ['entity' => 'Article', 'action' => 'create', 'changes' => ['label' => 'New']]);
        self::assertCount(1, $service->recent('recovery-account')['items']);
        $disabled = new AgentOperationService($em, new MutationAdapterRegistry([]));
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
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
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
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
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
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
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
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
        $preview = $service->prepare('restore-false-account', ['entity' => 'Article', 'action' => 'create', 'changes' => ['enabled' => false]]);
        $adapter->preparedChanges = ['enabled' => 0];
        $this->expectException(\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException::class);
        $service->restore('restore-false-account', $preview['operationId']);
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
