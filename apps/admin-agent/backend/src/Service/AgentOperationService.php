<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Service;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AgentMutationPolicy;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Generic lifecycle only. Target lookup, permissions, field rules and domain effects belong to adapters. */
final class AgentOperationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MutationAdapterRegistry $adapters,
        private readonly AdminConfigurationPolicy $configuration,
    ) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepare(string $actor, array $input): array
    {
        if (array_diff(array_keys($input), ['entity', 'action', 'identifier', 'changes']) !== []
            || !is_string($input['entity'] ?? null) || !in_array($input['action'] ?? null, ['create', 'update', 'delete'], true)) {
            throw new BadRequestHttpException('A registered entity and a single create/update/delete action are required.');
        }
        $identifier = $input['identifier'] ?? null;
        if ($identifier !== null && (!is_string($identifier) || strlen($identifier) > 128 || preg_match('/[\x00-\x1f\x7f]/', $identifier))) {
            throw new BadRequestHttpException('Invalid target identifier.');
        }
        if ($input['action'] === 'create' && $identifier !== null) throw new BadRequestHttpException('Creation cannot specify an identifier.');
        $changes = $input['changes'] ?? [];
        if (!is_array($changes) || ($changes !== [] && array_is_list($changes))) throw new BadRequestHttpException('Changes must be a field object.');
        if ($input['action'] === 'delete' && $changes !== []) throw new BadRequestHttpException('Deletion cannot include field changes.');

        $adapter = $this->adapters->get($input['entity']);
        AgentMutationPolicy::assertPrepareAllowed($input['entity'], $input['action']);
        $adapter->authorize($input['action'], $identifier);
        $this->configuration->assertPrepareAllowed($input['entity'], $input['action'], $changes);
        $draft = $adapter->prepare($input['action'], $identifier, $changes);
        if ($draft['differences'] === []) throw new BadRequestHttpException('The proposed update contains no effective changes.');
        if (strlen(json_encode($draft['differences'], JSON_THROW_ON_ERROR)) > 60000) throw new BadRequestHttpException('The full preview exceeds 60 KB. Use the standard editor instead.');
        $operation = new AgentOperation($actor, $adapter->entityKey(), $input['action'], $draft['identifier'], $draft['beforeHash'], $draft['changes']);
        $this->em->persist($operation);
        $this->em->flush();
        return ['operationId' => $operation->getId(), 'entity' => $operation->getEntityKey(), 'action' => $operation->getAction(),
            'identifier' => $draft['identifier'], 'title' => $draft['title'], 'affectedRecords' => 1,
            'expiresAt' => $operation->getExpiresAt()->format(DATE_ATOM), 'differences' => $draft['differences'], 'saved' => false];
    }

    /** @return array<string, mixed> */
    public function commit(string $actor, string $id): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $id): array {
            $operation = $this->operation($actor, $id, true);
            $adapter = $this->adapters->get($operation->getEntityKey());
            $adapter->authorize($operation->getAction(), $operation->getTargetId());
            if ($operation->getStatus() === 'succeeded') return $operation->receipt();
            AgentMutationPolicy::assertExistingProposalAllowed($operation->getEntityKey(), $operation->getAction());
            if ($operation->getStatus() !== 'pending' || $operation->getExpiresAt() <= new \DateTimeImmutable()) {
                throw new ConflictHttpException('This operation is cancelled or expired. Prepare a new preview.');
            }
            $snapshot = $this->configuration->snapshot();
            $this->configuration->assertMutationAllowed(
                $operation->getEntityKey(),
                $operation->getAction(),
                $operation->getChanges(),
                $snapshot,
            );
            $result = $adapter->execute($operation->getAction(), $operation->getTargetId(), $operation->getBeforeHash(), $operation->getChanges());
            if (!$result['verified']) throw new \RuntimeException('Mutation read-back verification failed.');
            $operation->complete($result['identifier']);
            $this->em->flush();
            return [...$operation->receipt(), 'verified' => true];
        });
    }

    /** @return array<string, mixed> */
    public function status(string $actor, string $id): array
    {
        $operation = $this->operation($actor, $id);
        $this->adapters->get($operation->getEntityKey())->authorize($operation->getAction(), $operation->getTargetId());
        return $operation->receipt();
    }

    /** Metadata only, with bounded pages. Inaccessible or disabled adapters are omitted.
     * @return array{items: list<array<string, mixed>>, page: int, hasMore: bool}
     */
    public function recent(string $actor, int $page = 1): array
    {
        if ($page < 1 || $page > 100) throw new BadRequestHttpException('Page must be between 1 and 100.');
        $operations = $this->em->getRepository(AgentOperation::class)->findBy(
            ['actor' => $actor], ['createdAt' => 'DESC', 'id' => 'DESC'], 21, ($page - 1) * 20,
        );
        $items = [];
        foreach (array_slice($operations, 0, 20) as $operation) {
            try {
                $this->adapters->get($operation->getEntityKey())->authorize($operation->getAction(), $operation->getTargetId());
            } catch (AccessDeniedException|BadRequestHttpException|NotFoundHttpException) {
                continue;
            }
            $items[] = $operation->receipt();
        }
        return ['items' => $items, 'page' => $page, 'hasMore' => count($operations) > 20 && $page < 100];
    }

    /** Rebuild a preview for the SAME proposal, without extending expiry or writing target data.
     * @return array<string, mixed>
     */
    public function restore(string $actor, string $id): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $id): array {
            $operation = $this->operation($actor, $id, true);
            $adapter = $this->adapters->get($operation->getEntityKey());
            AgentMutationPolicy::assertExistingProposalAllowed($operation->getEntityKey(), $operation->getAction());
            $adapter->authorize($operation->getAction(), $operation->getTargetId());
            if ($operation->receipt()['status'] !== 'pending') throw new ConflictHttpException('Only an unexpired pending operation can be restored. Check its outcome.');
            $snapshot = $this->configuration->snapshot();
            $this->configuration->assertMutationAllowed(
                $operation->getEntityKey(),
                $operation->getAction(),
                $operation->getChanges(),
                $snapshot,
            );
            try {
                $draft = $adapter->prepare($operation->getAction(), $operation->getTargetId(), $operation->getChanges());
            } catch (NotFoundHttpException $error) {
                throw new ProposalConflictException('The target no longer exists. Prepare a new preview.', $error);
            }
            if (!hash_equals($operation->getBeforeHash(), $draft['beforeHash']) || self::canonicalizeMaps($operation->getChanges()) !== self::canonicalizeMaps($draft['changes'])
                || $operation->getTargetId() !== $draft['identifier']) {
                throw new ProposalConflictException('The target or mutation policy changed. Discard this proposal and prepare a new preview.');
            }
            if ($draft['differences'] === [] || strlen(json_encode($draft['differences'], JSON_THROW_ON_ERROR)) > 60000) {
                throw new ConflictHttpException('This proposal can no longer be previewed safely. Use the standard editor.');
            }
            return ['operationId' => $operation->getId(), 'entity' => $operation->getEntityKey(), 'action' => $operation->getAction(),
                'identifier' => $operation->getTargetId(), 'title' => $draft['title'], 'affectedRecords' => 1,
                'expiresAt' => $operation->getExpiresAt()->format(DATE_ATOM), 'differences' => $draft['differences'], 'saved' => false];
        });
    }

    /** @return array<string, mixed> */
    public function cancel(string $actor, string $id): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $id): array {
            $operation = $this->operation($actor, $id, true);
            $this->adapters->get($operation->getEntityKey())->authorize($operation->getAction(), $operation->getTargetId());
            if ($operation->getStatus() === 'pending') $operation->cancel();
            return $operation->receipt();
        });
    }

    private function operation(string $actor, string $id, bool $lock = false): AgentOperation
    {
        $operation = preg_match('/^[a-f0-9]{64}$/D', $id)
            ? $this->em->find(AgentOperation::class, $id, $lock ? LockMode::PESSIMISTIC_WRITE : null) : null;
        // find() can return an already managed object; a lock alone does not refresh its fields.
        if ($lock && $operation instanceof AgentOperation) $this->em->refresh($operation, LockMode::PESSIMISTIC_WRITE);
        if (!$operation instanceof AgentOperation || $operation->getActor() !== $actor) throw new NotFoundHttpException('Assistant operation not found.');
        return $operation;
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canonicalizeMaps(array $value): array
    {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = self::canonicalizeMaps($item);
        }
        return $value;
    }
}
