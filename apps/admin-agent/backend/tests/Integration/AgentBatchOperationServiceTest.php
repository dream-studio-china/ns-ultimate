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
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchAgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchCreateAdapterRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AgentBatchOperationServiceTest extends IntegrationWebTestCase
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

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testExecuteFailureAfterEarlierRowsRollsBackAllRowsAndKeepsProposalPending(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = new BatchAgentOperationService(
            $em,
            new BatchCreateAdapterRegistry([new PartiallyFailingBatchCreateAdapter($em)]),
            new AdminConfigurationPolicy($this->writeManifest([
                'Fixture' => $this->manifestEntity('Fixture', ['name', 'slug']),
            ])),
        );
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

    public function testConfigurationGateAppliesPerRowAndRevokesPendingBatchButKeepsStatus(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $adapter = new PartiallyFailingBatchCreateAdapter($em);
        $manifest = $this->writeManifest([
            'Fixture' => $this->manifestEntity('Fixture', ['name', 'slug']),
        ]);
        $service = new BatchAgentOperationService(
            $em,
            new BatchCreateAdapterRegistry([$adapter]),
            new AdminConfigurationPolicy($manifest),
        );

        try {
            $service->prepare('batch-gate-actor', [
                'entity' => 'Fixture',
                'action' => 'create',
                'rows' => [['name' => 'Gate One', 'slug' => 'gate-one', 'extra' => 'No']],
            ]);
            self::fail('An unconfigured batch row field must be rejected.');
        } catch (BadRequestHttpException) {
            // Expected.
        }

        $preview = $service->prepare('batch-gate-actor', [
            'entity' => 'Fixture',
            'action' => 'create',
            'rows' => [['name' => 'Gate One', 'slug' => 'gate-one']],
        ]);
        $this->writeManifest([
            'Fixture' => $this->manifestEntity('Fixture', ['name']),
        ]);

        try {
            $service->commit('batch-gate-actor', $preview['operationId']);
            self::fail('A revoked row field must become a proposal conflict.');
        } catch (ProposalConflictException) {
            // Expected.
        }

        self::ensureKernelShutdown();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = new BatchAgentOperationService(
            $em,
            new BatchCreateAdapterRegistry([$adapter]),
            new AdminConfigurationPolicy($manifest),
        );
        self::assertSame('pending', $service->status('batch-gate-actor', $preview['operationId'])['status']);
        self::assertCount(0, $em->getConnection()->fetchAllAssociative(
            "SELECT id FROM common_tag WHERE slug = 'gate-one'",
        ));
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
        $this->manifestPath ??= sys_get_temp_dir().'/admin-agent-batch-policy-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($this->manifestPath, json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->manifestPath;
    }

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
    private function manifestEntity(string $name, array $fields): array
    {
        return [
            'key' => $name,
            'name' => $name,
            'actions' => [
                'create' => [
                    'configured' => true,
                    'fields' => array_map(static fn (string $property): array => ['property' => $property], $fields),
                ],
                'update' => ['configured' => false, 'fields' => []],
                'delete' => ['configured' => false, 'fields' => []],
            ],
        ];
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
