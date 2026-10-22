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
}

/** Test fixture, not registered in production DI or enabled as a domain capability. */
final class RecordingMutationAdapter implements MutationAdapter
{
    public int $executions = 0;
    public bool $verified = true;
    public function __construct(private readonly string $key) {}
    public function entityKey(): string { return $this->key; }
    public function authorize(string $action, ?string $identifier): void {}
    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        return ['identifier' => $identifier, 'title' => 'Fixture', 'beforeHash' => str_repeat('a', 64),
            'changes' => $changes, 'differences' => [['property' => 'label', 'before' => 'Old', 'after' => $changes['label']]]];
    }
    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->executions += 1;
        return ['identifier' => $identifier ?? 'new:record', 'verified' => $this->verified];
    }
}
