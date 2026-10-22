<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Service;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Generic lifecycle only. Target lookup, permissions, field rules and domain effects belong to adapters. */
final class AgentOperationService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly MutationAdapterRegistry $adapters) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepare(string $actor, array $input): array
    {
        if (array_diff(array_keys($input), ['entity', 'action', 'identifier', 'changes']) !== []
            || !is_string($input['entity'] ?? null) || !in_array($input['action'] ?? null, ['create', 'update', 'delete'], true)) {
            throw new BadRequestHttpException('A registered entity and a single create/update/delete action are required.');
        }
        $adapter = $this->adapters->get($input['entity']);
        $identifier = $input['identifier'] ?? null;
        if ($identifier !== null && (!is_string($identifier) || strlen($identifier) > 128 || preg_match('/[\x00-\x1f\x7f]/', $identifier))) {
            throw new BadRequestHttpException('Invalid target identifier.');
        }
        if ($input['action'] === 'create' && $identifier !== null) throw new BadRequestHttpException('Creation cannot specify an identifier.');
        $adapter->authorize($input['action'], $identifier);
        $changes = $input['changes'] ?? [];
        if (!is_array($changes) || ($changes !== [] && array_is_list($changes))) throw new BadRequestHttpException('Changes must be a field object.');
        if ($input['action'] === 'delete' && $changes !== []) throw new BadRequestHttpException('Deletion cannot include field changes.');
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
            if ($operation->getStatus() !== 'pending' || $operation->getExpiresAt() <= new \DateTimeImmutable()) {
                throw new ConflictHttpException('This operation is cancelled or expired. Prepare a new preview.');
            }
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
        if (!$operation instanceof AgentOperation || $operation->getActor() !== $actor) throw new NotFoundHttpException('Assistant operation not found.');
        return $operation;
    }
}
