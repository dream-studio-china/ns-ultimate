<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Service;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\BatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentBatchOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Explicit create-only batch lifecycle. Domain fields, uniqueness and dependencies remain
 * adapter-owned; this service only bounds shape, previews, ownership, expiry and receipts.
 */
final class BatchAgentOperationService
{
    private const MAX_ROWS = 20;

    public function __construct(private readonly EntityManagerInterface $em, private readonly BatchCreateAdapterRegistry $adapters) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepare(string $actor, array $input): array
    {
        if (array_diff(array_keys($input), ['entity', 'action', 'rows']) !== []
            || !is_string($input['entity'] ?? null) || ($input['action'] ?? null) !== 'create') {
            throw new BadRequestHttpException('A registered entity, the create action and a rows list are required.');
        }
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > self::MAX_ROWS) {
            throw new BadRequestHttpException('A batch must contain between 1 and 20 rows.');
        }
        $adapter = $this->adapters->get($input['entity']);
        $adapter->authorize();
        [$canonical, $preview] = $this->preparedRows($adapter, $rows);
        $operation = new AgentBatchOperation($actor, $adapter->entityKey(), 'create', $canonical);
        $this->em->persist($operation);
        $this->em->flush();

        return $this->preview($operation, $preview);
    }

    /** @return array<string, mixed> */
    public function commit(string $actor, string $id): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $id): array {
            $operation = $this->operation($actor, $id, true);
            $adapter = $this->adapters->get($operation->getEntityKey());
            $adapter->authorize();
            if ($operation->getStatus() === 'succeeded') {
                // The original transaction verified read-back. A replay returns that same
                // settled receipt, including the verification marker, without writing again.
                return [...$operation->receipt(), 'verified' => true];
            }
            if ($operation->getStatus() !== 'pending' || $operation->getExpiresAt() <= new \DateTimeImmutable()) {
                throw new ConflictHttpException('This batch is cancelled or expired. Prepare a new preview.');
            }
            [$rows] = $this->revalidateStoredRows($operation, $adapter);
            $results = $adapter->execute($rows);
            if (!array_is_list($results) || count($results) !== count($rows)) {
                throw new \RuntimeException('Batch create read-back verification failed.');
            }
            $seen = [];
            foreach ($results as $index => $result) {
                if (!is_array($result) || ($result['row'] ?? null) !== $index + 1
                    || !is_string($result['identifier'] ?? null) || $result['identifier'] === ''
                    || isset($seen[$result['row']])) {
                    throw new \RuntimeException('Batch create read-back verification failed.');
                }
                $seen[$result['row']] = true;
            }
            $operation->complete($results);
            $this->em->flush();

            return [...$operation->receipt(), 'verified' => true];
        });
    }

    /** @return array<string, mixed> */
    public function status(string $actor, string $id): array
    {
        $operation = $this->operation($actor, $id);
        $this->adapters->get($operation->getEntityKey())->authorize();

        return $operation->receipt();
    }

    /** Metadata only, with bounded pages. Inaccessible or disabled adapters are omitted.
     * @return array{items: list<array<string, mixed>>, page: int, hasMore: bool}
     */
    public function recent(string $actor, int $page = 1): array
    {
        if ($page < 1 || $page > 100) {
            throw new BadRequestHttpException('Page must be between 1 and 100.');
        }
        $operations = $this->em->getRepository(AgentBatchOperation::class)->findBy(
            ['actor' => $actor], ['createdAt' => 'DESC', 'id' => 'DESC'], 21, ($page - 1) * 20,
        );
        $items = [];
        foreach (array_slice($operations, 0, 20) as $operation) {
            try {
                $this->adapters->get($operation->getEntityKey())->authorize();
            } catch (AccessDeniedException|BadRequestHttpException|NotFoundHttpException) {
                continue;
            }
            $items[] = $operation->receipt();
        }

        return ['items' => $items, 'page' => $page, 'hasMore' => count($operations) > 20 && $page < 100];
    }

    /** Rebuild a preview for the SAME batch proposal without extending expiry or writing target data.
     * @return array<string, mixed>
     */
    public function restore(string $actor, string $id): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $id): array {
            $operation = $this->operation($actor, $id, true);
            $adapter = $this->adapters->get($operation->getEntityKey());
            $adapter->authorize();
            if ($operation->receipt()['status'] !== 'pending') {
                throw new ConflictHttpException('Only an unexpired pending batch can be restored. Check its outcome.');
            }
            [, $preview] = $this->revalidateStoredRows($operation, $adapter);

            return $this->preview($operation, $preview);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(string $actor, string $id): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $id): array {
            $operation = $this->operation($actor, $id, true);
            $this->adapters->get($operation->getEntityKey())->authorize();
            if ($operation->getStatus() === 'pending') {
                $operation->cancel();
            }

            return $operation->receipt();
        });
    }

    /**
     * Verify the persisted proposal before preview or execution. The persisted rows, not a
     * re-normalized copy, are the values that may be executed.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function revalidateStoredRows(AgentBatchOperation $operation, BatchCreateAdapter $adapter): array
    {
        $rows = $operation->getRows();
        if (!array_is_list($rows) || count($rows) !== $operation->getRowCount() || $rows === []) {
            throw new ProposalConflictException('The stored batch payload is invalid. Discard this proposal and prepare a new preview.');
        }
        if (!hash_equals($operation->getRowsHash(), AgentBatchOperation::hashRows($rows))) {
            throw new ProposalConflictException('The stored batch payload changed. Discard this proposal and prepare a new preview.');
        }
        try {
            [$canonical, $preview] = $this->preparedRows($adapter, $rows);
        } catch (BadRequestHttpException|NotFoundHttpException $error) {
            throw new ProposalConflictException('The batch or its dependencies changed. Discard this proposal and prepare a new preview.', $error);
        }
        if (!hash_equals($operation->getRowsHash(), AgentBatchOperation::hashRows($canonical))) {
            throw new ProposalConflictException('The batch policy changed. Discard this proposal and prepare a new preview.');
        }

        return [$rows, $preview];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function preparedRows(BatchCreateAdapter $adapter, array $rows): array
    {
        $draft = $adapter->prepare($rows);
        $prepared = $draft['rows'] ?? null;
        if (!is_array($prepared) || !array_is_list($prepared) || count($prepared) !== count($rows)) {
            throw new \LogicException('Batch create adapter returned an invalid row set.');
        }
        $canonical = [];
        $preview = [];
        $identities = [];
        foreach ($prepared as $index => $row) {
            if (!is_array($row)
                || !is_string($row['title'] ?? null)
                || !is_array($row['changes'] ?? null)
                || ($row['changes'] !== [] && array_is_list($row['changes']))
                || !is_array($row['differences'] ?? null)
                || !array_is_list($row['differences'])
                || $row['differences'] === []
                || !is_string($row['identity'] ?? null)
                || $row['identity'] === '') {
                throw new \LogicException('Batch create adapter returned an invalid row preview.');
            }
            if (isset($identities[$row['identity']])) {
                throw new BadRequestHttpException('Duplicate rows are not accepted; remove duplicate row '.($index + 1).'.');
            }
            $identities[$row['identity']] = true;
            $canonical[] = $row['changes'];
            $preview[] = ['row' => $index + 1, 'title' => $row['title'], 'identifier' => null, 'differences' => $row['differences']];
        }
        if (strlen(json_encode(array_column($preview, 'differences'), JSON_THROW_ON_ERROR)) > 60000) {
            throw new BadRequestHttpException('The full preview exceeds 60 KB. Use the standard editor instead.');
        }

        return [$canonical, $preview];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function preview(AgentBatchOperation $operation, array $rows): array
    {
        return ['operationId' => $operation->getId(), 'entity' => $operation->getEntityKey(), 'action' => $operation->getAction(),
            'title' => sprintf('Create %d %s record%s', $operation->getRowCount(), $operation->getEntityKey(), $operation->getRowCount() === 1 ? '' : 's'),
            'affectedRecords' => $operation->getRowCount(), 'expiresAt' => $operation->getExpiresAt()->format(DATE_ATOM),
            'rows' => $rows, 'saved' => false];
    }

    private function operation(string $actor, string $id, bool $lock = false): AgentBatchOperation
    {
        $operation = preg_match('/^[a-f0-9]{64}$/D', $id)
            ? $this->em->find(AgentBatchOperation::class, $id, $lock ? LockMode::PESSIMISTIC_WRITE : null) : null;
        // find() can return an already managed object; a lock alone does not refresh its fields.
        if ($lock && $operation instanceof AgentBatchOperation) {
            $this->em->refresh($operation, LockMode::PESSIMISTIC_WRITE);
        }
        if (!$operation instanceof AgentBatchOperation || $operation->getActor() !== $actor) {
            throw new NotFoundHttpException('Assistant batch operation not found.');
        }

        return $operation;
    }
}
