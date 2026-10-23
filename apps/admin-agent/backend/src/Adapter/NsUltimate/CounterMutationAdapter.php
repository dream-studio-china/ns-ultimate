<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\AdminCrudExecutor;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Service\CounterWriteService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Agent-specific Counter policy: permissions, previews, conflicts and receipts.
 * Domain writes are executed by the standard Counter administration controller
 * through the audited bridge; CounterWriteService remains the read-only
 * normalization/lookup/reference policy.
 */
final class CounterMutationAdapter implements MutationAdapter
{
    /**
     * App-side exposure policy, deliberately separate from the domain schema so a
     * new business field is never exposed to the assistant automatically. Metadata
     * is never writable or disclosed here.
     */
    private const EXPOSED_FIELDS = ['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CounterWriteService $writes,
        private readonly AdminCrudExecutor $crud,
    ) {
    }

    public function entityKey(): string { return 'Counter'; }

    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Counter management requires ROLE_ADMIN.');
        if (!in_array($action, ['create', 'update', 'delete'], true)) throw new BadRequestHttpException('Unsupported Counter action.');
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        if ($action === 'delete' && $changes !== []) throw new BadRequestHttpException('Counter deletion does not accept fields.');
        try {
            $target = $action === 'create' ? null : $this->writes->find($identifier);
            if ($action === 'delete') {
                if (!$target instanceof Counter) throw new \LogicException('A Counter target is required for deletion.');
                $this->writes->assertUnreferenced($target);
            }
            $normalized = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create');
            $before = $target ? $this->snapshot($target) : null;
            $after = $before ?? ['storeUuid' => '', 'code' => '', 'name' => '', 'capacity' => null, 'sort' => 0, 'enabled' => true];
            if ($action !== 'delete') $after = array_replace($after, $normalized);
            if ($action !== 'delete') {
                if ($action === 'create') {
                    $store = $this->writes->requireStore($after['storeUuid']);
                    if (!$store->isActive()) throw new \InvalidArgumentException('Counter store must exist and be active.');
                }
                $this->writes->assertUnique($after['storeUuid'], $after['code'], $target?->getId());
            }
        } catch (\InvalidArgumentException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        } catch (\OutOfBoundsException $error) {
            throw new NotFoundHttpException($error->getMessage(), $error);
        }

        $fields = $action === 'delete' ? self::EXPOSED_FIELDS : array_values(array_intersect(self::EXPOSED_FIELDS, array_keys($normalized)));
        $differences = [];
        foreach ($fields as $field) if ($action === 'delete' || $action !== 'update' || ($before[$field] ?? null) !== ($after[$field] ?? null)) $differences[] = ['property' => $field, 'before' => $before[$field] ?? null, 'after' => $action === 'delete' ? null : ($after[$field] ?? null)];
        $title = $action === 'delete' ? ($before['storeUuid'] ?? '').' / '.($before['name'] ?? '') : ($before['name'] ?? $after['name']);
        return ['identifier' => $target ? (string) $target->getId() : null, 'title' => $title, 'beforeHash' => $this->hash($before), 'changes' => $normalized, 'differences' => $differences];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        if ($action === 'delete' && $changes !== []) throw new BadRequestHttpException('Counter deletion does not accept fields.');
        try { $changes = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create'); }
        catch (\InvalidArgumentException $error) { throw new ProposalConflictException('Counter proposal is no longer valid. Prepare a new preview.', $error); }

        if ($action === 'create') {
            try {
                $store = $this->writes->requireStore($changes['storeUuid'], true);
                if (!$store->isActive()) throw new \InvalidArgumentException('Counter store must exist and be active.');
                $this->writes->assertUnique($changes['storeUuid'], $changes['code']);
            } catch (\InvalidArgumentException $error) {
                throw new ProposalConflictException('Counter store or code changed after preview. Prepare a new preview.', $error);
            }
            $id = $this->crud->execute($this->em, 'Counter', 'create', null, $changes);
            if ($id === null) throw new \RuntimeException('Counter create returned no identifier.');
            $persisted = $this->em->find(Counter::class, (int) $id);
            if (!$persisted instanceof Counter) throw new \RuntimeException('Counter mutation read-back verification failed.');
            $this->em->refresh($persisted);
            $expected = array_replace($this->entityDefaults(), $changes);
            return ['identifier' => $id, 'verified' => $this->comparableSnapshot($persisted) === $this->comparable($expected)];
        }

        try { $target = $this->writes->find($identifier, true); }
        catch (\OutOfBoundsException $error) { throw new ProposalConflictException('Counter no longer exists. Prepare a new preview.', $error); }
        $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
        if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Counter changed since preview. Prepare a new preview.');
        if ($action === 'delete') {
            $this->revalidate(fn () => $this->writes->assertUnreferenced($target), 'Counter acquired a binding after preview and cannot be deleted. Prepare a new preview.');
            $id = (string) $target->getId();
            $this->crud->execute($this->em, 'Counter', 'delete', $identifier, []);
            $remaining = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM counter WHERE id = ?', [$id]);
            return ['identifier' => $id, 'verified' => (int) $remaining === 0];
        }
        if (array_key_exists('storeUuid', $changes)) throw new BadRequestHttpException('A Counter cannot be moved to another store.');
        // Compute the expected state from the locked snapshot without mutating the
        // actual target; the standard controller performs the only write.
        $expected = array_replace($this->snapshot($target), $changes);
        $this->revalidate(fn () => $this->writes->assertUnique($expected['storeUuid'], $expected['code'], $target->getId()), 'Counter code was claimed after preview. Prepare a new preview.');
        $id = (string) $target->getId();
        $this->crud->execute($this->em, 'Counter', 'update', $identifier, $changes);
        $persisted = $this->em->find(Counter::class, (int) $id);
        if (!$persisted instanceof Counter) throw new \RuntimeException('Counter mutation read-back verification failed.');
        $this->em->refresh($persisted);

        return ['identifier' => $id, 'verified' => $this->comparableSnapshot($persisted) === $this->comparable($expected)];
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed> */
    private function normalize(array $changes, bool $create): array
    {
        if (array_diff(array_keys($changes), self::EXPOSED_FIELDS) !== []) throw new \InvalidArgumentException('Invalid Counter fields.');
        return $this->writes->normalize($create ? 'create' : 'update', $changes);
    }

    private function revalidate(callable $callback, string $message): void
    {
        try { $callback(); }
        catch (\InvalidArgumentException $error) { throw new ProposalConflictException($message, $error); }
    }

    /** @return array<string, mixed> */
    private function snapshot(Counter $target): array
    {
        return ['id'=>$target->getId(),'uuid'=>$target->getUuid(),'storeUuid'=>$target->getStoreUuid(),'code'=>$target->getCode(),'name'=>$target->getName(),'capacity'=>$target->getCapacity(),'sort'=>$target->getSort(),'metadata'=>$target->getMetadata(),'enabled'=>$target->getEnabled(),'createdAt'=>$target->getCreatedAt()->format('Y-m-d H:i:s'),'updatedAt'=>$target->getUpdatedAt()?->format('Y-m-d H:i:s')];
    }

    /**
     * Create defaults are read from the entity definition so the expected state is
     * computed without constructing or mutating a real target.
     *
     * @return array<string, mixed>
     */
    private function entityDefaults(): array
    {
        $defaults = (new \ReflectionClass(Counter::class))->getDefaultProperties();
        $defaults = array_intersect_key($defaults, array_fill_keys(self::EXPOSED_FIELDS, true));
        $defaults['metadata'] = null;

        return $defaults;
    }

    /** @return array<string, mixed> */
    private function comparableSnapshot(Counter $target): array
    {
        return $this->comparable($this->snapshot($target));
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed> */
    private function comparable(array $value): array
    {
        return $this->canonicalize(array_intersect_key($value, array_fill_keys([...self::EXPOSED_FIELDS, 'metadata'], true)));
    }

    /** @param array<string, mixed>|null $snapshot */
    private function hash(?array $snapshot): string { return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR)); }
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
