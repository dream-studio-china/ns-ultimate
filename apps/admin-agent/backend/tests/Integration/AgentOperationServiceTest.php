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
}

/** Test fixture, not registered in production DI or enabled as a domain capability. */
final class RecordingMutationAdapter implements MutationAdapter
{
    public int $executions = 0;
    public bool $verified = true;
    public bool $authorized = true;
    public bool $policyChanged = false;
    public function __construct(private readonly string $key) {}
    public function entityKey(): string { return $this->key; }
    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorized) throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
    }
    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        if ($this->policyChanged) $changes['label'] = 'Changed normalization';
        return ['identifier' => $identifier, 'title' => 'Fixture', 'beforeHash' => str_repeat('a', 64),
            'changes' => $changes, 'differences' => [['property' => 'label', 'before' => 'Old', 'after' => $changes['label']]]];
    }
    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->executions += 1;
        return ['identifier' => $identifier ?? 'new:record', 'verified' => $this->verified];
    }
}
