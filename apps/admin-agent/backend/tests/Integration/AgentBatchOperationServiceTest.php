<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use App\Common\Entity\Tag;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\BatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentBatchOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchAgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchCreateAdapterRegistry;

final class AgentBatchOperationServiceTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testExecuteFailureAfterEarlierRowsRollsBackAllRowsAndKeepsProposalPending(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = new BatchAgentOperationService($em, new BatchCreateAdapterRegistry([
            new PartiallyFailingBatchCreateAdapter($em),
        ]));
        $preview = $service->prepare('batch-rollback-service-actor', [
            'entity' => 'Fixture',
            'action' => 'create',
            'rows' => [
                ['name' => 'Fixture Rollback One', 'slug' => 'fixture-rollback-one'],
                ['name' => 'Fixture Rollback Two', 'slug' => 'fixture-rollback-two'],
            ],
        ]);

        try {
            $service->commit('batch-rollback-service-actor', $preview['operationId']);
            self::fail('A row failure must reject the whole batch.');
        } catch (ProposalConflictException) {
            // Expected: the generic transaction rolls back the earlier row.
        }

        $connection = $em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM common_tag WHERE slug IN ('fixture-rollback-one', 'fixture-rollback-two')",
        ));
        $operation = $em->find(AgentBatchOperation::class, $preview['operationId']);
        self::assertSame('pending', $operation->getStatus());
        self::assertCount(2, $operation->getRows());
        $status = $connection->fetchOne('SELECT status FROM admin_agent_batch_operation WHERE id = ?', [$preview['operationId']]);
        self::assertSame('pending', $status);
    }
}

/** Test fixture, not registered in production DI or enabled as a domain capability. */
final class PartiallyFailingBatchCreateAdapter implements BatchCreateAdapter
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function entityKey(): string
    {
        return 'Fixture';
    }

    public function authorize(): void {}

    public function prepare(array $rows): array
    {
        $prepared = [];
        foreach ($rows as $row) {
            $prepared[] = [
                'title' => $row['name'],
                'changes' => $row,
                'differences' => [['property' => 'name', 'before' => null, 'after' => $row['name']]],
                'identity' => $row['slug'],
            ];
        }

        return ['rows' => $prepared];
    }

    public function execute(array $rows): array
    {
        $results = [];
        foreach ($rows as $index => $changes) {
            if ($index === 1) {
                throw new ProposalConflictException('Fixture row failure.');
            }
            $tag = new Tag($changes['name'], $changes['slug']);
            $this->em->persist($tag);
            $this->em->flush();
            $results[] = ['row' => $index + 1, 'identifier' => (string) $tag->getId()];
        }

        return $results;
    }
}
