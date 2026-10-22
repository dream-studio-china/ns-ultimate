<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Counter\Entity\Counter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Agent-specific, name-only binding for existing Counter records. */
final class CounterMutationAdapter implements MutationAdapter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {}

    public function entityKey(): string { return 'Counter'; }

    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Counter management requires ROLE_ADMIN.');
        }
        if ($action !== 'update') {
            throw new BadRequestHttpException('Only Counter updates are supported.');
        }
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        if (array_diff(array_keys($changes), ['name']) !== [] || !array_key_exists('name', $changes)) {
            throw new BadRequestHttpException('Only the Counter name may be changed.');
        }
        $name = $changes['name'];
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 120) {
            throw new BadRequestHttpException('name must be non-empty and at most 120 characters.');
        }
        $changes = ['name' => trim($name)];
        $target = $this->findTarget($identifier);
        $before = $this->snapshot($target);
        $afterName = $changes['name'];
        $differences = $before['name'] === $afterName ? [] : [[
            'property' => 'name', 'before' => $before['name'], 'after' => $afterName,
        ]];

        return [
            'identifier' => (string) $target->getId(),
            'title' => $before['name'],
            'beforeHash' => $this->hash($before),
            'changes' => $changes,
            'differences' => $differences,
        ];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        if (array_keys($changes) !== ['name'] || !is_string($changes['name'])
            || trim($changes['name']) === '' || mb_strlen(trim($changes['name'])) > 120) {
            throw new BadRequestHttpException('Invalid Counter name proposal.');
        }
        try {
            $target = $this->findTarget($identifier, true);
        } catch (NotFoundHttpException $error) {
            throw new ProposalConflictException('Counter no longer exists. Prepare a new preview.', $error);
        }
        $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
        if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) {
            throw new ProposalConflictException('Counter changed since preview. Prepare a new preview.');
        }

        $target->setName(trim($changes['name']));
        $expected = $target->getName();
        $id = (string) $target->getId();
        $this->em->flush();
        $this->em->refresh($target);

        return ['identifier' => $id, 'verified' => $target->getName() === $expected];
    }

    private function findTarget(?string $identifier, bool $lock = false): Counter
    {
        if ($identifier === null || !preg_match('/^(?:[1-9][0-9]{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/iD', $identifier)) {
            throw new BadRequestHttpException('An exact Counter ID or UUID is required.');
        }
        $target = ctype_digit($identifier)
            ? $this->em->find(Counter::class, (int) $identifier, $lock ? LockMode::PESSIMISTIC_WRITE : null)
            : $this->em->getRepository(Counter::class)->findOneBy(['uuid' => strtolower($identifier)]);
        if (!$target instanceof Counter) throw new NotFoundHttpException('Counter not found.');
        return $target;
    }

    /** Snapshot values are hashed only and never returned to the assistant. */
    private function snapshot(Counter $target): array
    {
        return [
            'id' => $target->getId(), 'uuid' => $target->getUuid(), 'storeUuid' => $target->getStoreUuid(),
            'code' => $target->getCode(), 'name' => $target->getName(), 'capacity' => $target->getCapacity(),
            'sort' => $target->getSort(), 'metadata' => $target->getMetadata(), 'enabled' => $target->getEnabled(),
            // Canonicalize to the mapped DB precision; supported schemas do not
            // guarantee preservation of DateTime microseconds or timezone data.
            'createdAt' => $target->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $target->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
