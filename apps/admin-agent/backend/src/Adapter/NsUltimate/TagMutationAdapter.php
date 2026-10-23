<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Common\Entity\Tag;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\AdminCrudExecutor;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Common\Service\TagWriteService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Agent-specific Tag policy: permissions, previews, conflicts and receipts. Domain
 * writes are executed by the standard Tag administration controller through the
 * audited bridge; TagWriteService remains the read-only normalization/lookup policy.
 */
final class TagMutationAdapter implements MutationAdapter
{
    /**
     * App-side exposure policy, deliberately separate from the domain schema so a
     * new business field is never exposed to the assistant automatically.
     */
    private const EXPOSED_FIELDS = ['name', 'slug', 'color'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly TagWriteService $writes,
        private readonly AdminCrudExecutor $crud,
    ) {
    }

    public function entityKey(): string { return 'Tag'; }

    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Tag management requires ROLE_ADMIN.');
        if (!in_array($action, ['create', 'update', 'delete'], true)) throw new BadRequestHttpException('Unsupported Tag action.');
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        try {
            $target = $action === 'create' ? null : $this->writes->find($identifier);
            if ($action === 'delete') {
                if (!$target instanceof Tag) throw new \LogicException('A Tag target is required for deletion.');
                $this->writes->assertUnused($target);
            }
            $changes = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create');
            $before = $target ? $this->snapshot($target) : null;
            $after = $before ?? ['name' => '', 'slug' => '', 'color' => null];
            if ($action !== 'delete') $after = array_replace($after, $changes);
            if ($action !== 'delete') $this->writes->assertUniqueSlug($after['slug'], $target?->getId());
        } catch (\InvalidArgumentException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        } catch (\OutOfBoundsException $error) {
            throw new NotFoundHttpException($error->getMessage(), $error);
        }

        $fields = $action === 'delete' ? self::EXPOSED_FIELDS : array_values(array_intersect(self::EXPOSED_FIELDS, array_keys($changes)));
        $differences = [];
        foreach ($fields as $field) if ($action !== 'update' || ($before[$field] ?? null) !== ($after[$field] ?? null)) $differences[] = ['property' => $field, 'before' => $before[$field] ?? null, 'after' => $action === 'delete' ? null : ($after[$field] ?? null)];
        return ['identifier' => $target ? (string) $target->getId() : null, 'title' => $before['name'] ?? $after['name'], 'beforeHash' => $this->hash($before), 'changes' => $changes, 'differences' => $differences];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        try { $changes = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create'); }
        catch (\InvalidArgumentException $error) { throw new ProposalConflictException('Tag proposal is no longer valid. Prepare a new preview.', $error); }

        if ($action === 'create') {
            $this->revalidate(fn () => $this->writes->assertUniqueSlug($changes['slug']), 'Tag slug was claimed after preview. Prepare a new preview.');
            $id = $this->crud->execute($this->em, 'Tag', 'create', null, $changes);
            if ($id === null) throw new \RuntimeException('Tag create returned no identifier.');
            $persisted = $this->freshManager()->find(Tag::class, (int) $id);
            if (!$persisted instanceof Tag) throw new \RuntimeException('Tag mutation read-back verification failed.');
            $expected = array_replace(['name' => '', 'slug' => '', 'color' => null], $changes);
            return ['identifier' => $id, 'verified' => $this->exposedSnapshot($persisted) === array_intersect_key($expected, array_fill_keys(self::EXPOSED_FIELDS, true))];
        }

        try { $target = $this->writes->find($identifier, true); }
        catch (\OutOfBoundsException $error) { throw new ProposalConflictException('Tag no longer exists. Prepare a new preview.', $error); }
        $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
        if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Tag changed since preview. Prepare a new preview.');

        if ($action === 'delete') {
            $this->revalidate(fn () => $this->writes->assertUnused($target, true), 'Tag associations changed after preview. Prepare a new preview.');
            $id = (string) $target->getId();
            $this->crud->execute($this->em, 'Tag', 'delete', $identifier, []);
            $remaining = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM common_tag WHERE id = ?', [$id]);
            return ['identifier' => $id, 'verified' => (int) $remaining === 0];
        }

        $before = $this->snapshot($target);
        $expected = array_replace($before, $changes);
        $this->revalidate(fn () => $this->writes->assertUniqueSlug($expected['slug'], $target->getId()), 'Tag slug was claimed after preview. Prepare a new preview.');
        $this->crud->execute($this->em, 'Tag', 'update', $identifier, $changes);
        $persisted = $this->freshManager()->find(Tag::class, (int) $target->getId());
        if (!$persisted instanceof Tag) throw new \RuntimeException('Tag mutation read-back verification failed.');

        return [
            'identifier' => (string) $target->getId(),
            'verified' => $this->exposedSnapshot($persisted) === array_intersect_key($expected, array_fill_keys(self::EXPOSED_FIELDS, true)),
        ];
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed> */
    private function normalize(array $changes, bool $create): array
    {
        if (array_diff(array_keys($changes), self::EXPOSED_FIELDS) !== []) throw new \InvalidArgumentException('Invalid Tag fields.');
        return $this->writes->normalize($create ? 'create' : 'update', $changes);
    }

    private function revalidate(callable $callback, string $message): void
    {
        try { $callback(); }
        catch (\InvalidArgumentException $error) { throw new ProposalConflictException($message, $error); }
    }

    /** @return array<string, mixed> */
    private function snapshot(Tag $entity): array
    {
        return ['id' => $entity->getId(), 'uuid' => $entity->getUuid(), 'name' => $entity->getName(), 'slug' => $entity->getSlug(), 'color' => $entity->getColor()];
    }

    /** @return array<string, mixed> */
    private function exposedSnapshot(Tag $entity): array
    {
        return array_intersect_key($this->snapshot($entity), array_fill_keys(self::EXPOSED_FIELDS, true));
    }

    /**
     * A fresh manager on the same connection reads committed writes without
     * touching the outer operation's identity map. The controller flush remains
     * inside the caller-owned transaction.
     */
    private function freshManager(): EntityManagerInterface
    {
        return new EntityManager($this->em->getConnection(), $this->em->getConfiguration());
    }

    /** @param array<string, mixed>|null $value */
    private function hash(?array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
