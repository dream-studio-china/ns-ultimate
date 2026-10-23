<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Common\Entity\Category;
use App\Common\Entity\Content;
use App\Common\Entity\Media;
use App\Common\Entity\Picture;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CategoryMutationAdapter implements MutationAdapter
{
    private const FIELDS = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];

    /** @var array<class-string, string> */
    private const REFERENCE_FIELDS = [
        Category::class => 'parent',
        Content::class => 'category',
        Picture::class => 'category',
        Media::class => 'category',
    ];

    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $authorization) {}
    public function entityKey(): string { return 'Category'; }
    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Category management requires ROLE_ADMIN.');
        if (!in_array($action, ['create', 'update', 'delete'], true)) throw new BadRequestHttpException('Unsupported Category action.');
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        if ($action === 'delete') {
            $target = $this->find($identifier);
            $this->assertUnreferenced($target);
        } else {
            $target = $action === 'create' ? null : $this->find($identifier);
        }
        $normalized = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create');
        $before = $target ? $this->snapshot($target) : null;
        $after = null;
        if ($action !== 'delete') {
            $after = array_replace($before ?? ['name' => '', 'slug' => '', 'description' => null, 'parent' => null, 'sortOrder' => 0, 'enabled' => true], $normalized);
            $this->validateParent($after['parent'], $target);
            $this->assertUniqueSlug($after['slug'], $target?->getId());
        }
        $fields = $action === 'delete' ? self::FIELDS : array_values(array_intersect(self::FIELDS, array_keys($normalized)));
        $differences = [];
        foreach ($fields as $field) if ($action !== 'update' || ($before[$field] ?? null) !== ($after[$field] ?? null)) $differences[] = ['property' => $field, 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
        $title = $before['name'] ?? null;
        if ($title === null && $after !== null) $title = $after['name'] ?? null;
        return ['identifier' => $target ? (string) $target->getId() : null, 'title' => $title, 'beforeHash' => $this->hash($before), 'changes' => $normalized, 'differences' => $differences];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        if ($action === 'delete' || ($action === 'update' && array_key_exists('parent', $changes))) $this->lockHierarchy();
        try { $changes = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create'); }
        catch (BadRequestHttpException $error) { throw new ProposalConflictException('Category proposal is no longer valid. Prepare a new preview.', $error); }
        if ($action === 'create') {
            $target = new Category($changes['name'], $changes['slug']);
        } else {
            try { $target = $this->find($identifier, true); }
            catch (NotFoundHttpException $e) { throw new ProposalConflictException('Category no longer exists. Prepare a new preview.', $e); }
            $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
            if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Category changed since preview. Prepare a new preview.');
        }
        if ($action === 'delete') {
            $this->revalidate(fn () => $this->assertUnreferenced($target, true), 'Category references changed after preview. Prepare a new preview.');
            $id = (string) $target->getId(); $this->em->remove($target);
            try { $this->em->flush(); } catch (ForeignKeyConstraintViolationException $error) { throw new ProposalConflictException('Category acquired a reference after preview. Prepare a new preview.', $error); }
            return ['identifier' => $id, 'verified' => $this->em->find(Category::class, $id) === null];
        }
        $this->revalidate(function () use ($changes, $target): void {
            if (array_key_exists('parent', $changes)) $this->validateParent($changes['parent'], $target, $target->getId() === null);
            $slug = $changes['slug'] ?? $target->getSlug();
            $this->assertUniqueSlug($slug, $target->getId());
        }, 'Category hierarchy or slug changed after preview. Prepare a new preview.');
        $this->apply($target, $changes);
        $expected = array_intersect_key($this->snapshot($target), array_fill_keys(self::FIELDS, true)); $this->em->persist($target);
        try { $this->em->flush(); } catch (UniqueConstraintViolationException $error) { throw new ProposalConflictException('Category slug was claimed after preview. Prepare a new preview.', $error); }
        $this->em->refresh($target);
        return ['identifier' => (string) $target->getId(), 'verified' => array_intersect_key($this->snapshot($target), array_fill_keys(self::FIELDS, true)) === $expected];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function normalize(array $changes, bool $create): array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== [] || ($create && (!isset($changes['name']) || !isset($changes['slug'])))) throw new BadRequestHttpException('Invalid Category fields.');
        foreach ($changes as $field => $value) {
            $valid = match ($field) {
                'name' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255,
                'slug' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255,
                'description' => $value === null || (is_string($value) && strlen($value) <= 65535),
                'parent' => $value === null || (is_int($value) && $value > 0 && $value <= 2147483647) || (is_string($value) && preg_match('/^(?:[1-9][0-9]{0,9}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/iD', $value) && (!ctype_digit($value) || (int) $value <= 2147483647)),
                'sortOrder' => is_int($value) && $value >= 0 && $value <= 2147483647,
                'enabled' => is_bool($value), default => false,
            };
            if (!$valid) throw new BadRequestHttpException('Invalid Category field: '.$field.'.');
        }
        if (array_key_exists('parent', $changes) && $changes['parent'] !== null) $changes['parent'] = $this->findId($changes['parent']);
        return array_replace(array_intersect_key(['name'=>null,'slug'=>null,'description'=>null,'parent'=>null,'sortOrder'=>null,'enabled'=>null], $changes), $changes);
    }
    /** @param array<string, mixed> $changes */
    private function apply(Category $target, array $changes): void
    {
        if (array_key_exists('name', $changes)) $target->setName($changes['name']);
        if (array_key_exists('slug', $changes)) $target->setSlug($changes['slug']);
        if (array_key_exists('description', $changes)) $target->setDescription($changes['description']);
        if (array_key_exists('parent', $changes)) $target->setParent($changes['parent'] === null ? null : $this->em->find(Category::class, $changes['parent']));
        if (array_key_exists('sortOrder', $changes)) $target->setSortOrder($changes['sortOrder']);
        if (array_key_exists('enabled', $changes)) $target->setEnabled($changes['enabled']);
    }
    private function find(?string $id, bool $lock = false): Category
    {
        if ($id === null || !preg_match('/^(?:[1-9][0-9]{0,9}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/iD', $id) || (ctype_digit($id) && (int) $id > 2147483647)) throw new BadRequestHttpException('An exact Category ID or UUID is required.');
        $entity = ctype_digit($id) ? $this->em->find(Category::class, (int) $id, $lock ? LockMode::PESSIMISTIC_WRITE : null) : $this->em->getRepository(Category::class)->findOneBy(['uuid' => strtolower($id)]);
        if (!$entity instanceof Category) throw new NotFoundHttpException('Category not found.'); return $entity;
    }
    private function findId(mixed $id): int
    {
        $entity = is_int($id) ? $this->em->find(Category::class, $id) : (ctype_digit($id) ? $this->em->find(Category::class, (int) $id) : $this->em->getRepository(Category::class)->findOneBy(['uuid' => strtolower($id)]));
        if (!$entity instanceof Category) throw new BadRequestHttpException('Category parent not found.');
        $parentId = $entity->getId();
        if ($parentId === null) throw new BadRequestHttpException('Category parent is not stored.');
        return $parentId;
    }
    private function validateParent(?int $parent, ?Category $target, bool $lockParent = false): void
    {
        if ($parent === null) return;
        if ($target && $target->getId() === $parent) throw new BadRequestHttpException('A category cannot be its own parent.');
        $seen = []; $cursor = $this->em->find(Category::class, $parent, $lockParent ? LockMode::PESSIMISTIC_READ : null);
        if (!$cursor instanceof Category) throw new BadRequestHttpException('Category parent no longer exists.');
        while ($cursor instanceof Category) {
            $id = $cursor->getId();
            if ($id === null) throw new BadRequestHttpException('Category hierarchy contains an unsaved parent.');
            if (isset($seen[$id]) || ($target && $id === $target->getId())) throw new BadRequestHttpException('Category parent would create a cycle.');
            if (count($seen) >= 10000) throw new BadRequestHttpException('Category hierarchy exceeds the safe traversal limit.');
            $seen[$id] = true; $cursor = $cursor->getParent();
        }
    }
    private function assertUniqueSlug(string $slug, ?int $id): void { $other = $this->em->getRepository(Category::class)->findOneBy(['slug' => $slug]); if ($other && $other->getId() !== $id) throw new BadRequestHttpException('Category slug is already in use.'); }
    private function assertUnreferenced(Category $target, bool $lockReferences = false): void
    {
        $id = $target->getId();
        if ($id === null) throw new BadRequestHttpException('Category is not stored.');
        foreach (self::REFERENCE_FIELDS as $class => $field) {
            $referenced = $lockReferences ? $this->hasLockedReference($class, $field, $id) : $this->em->getRepository($class)->count([$field => $id]) > 0;
            if ($referenced) throw new BadRequestHttpException('Category is referenced and cannot be deleted.');
        }
    }
    /** @param class-string $class */
    private function hasLockedReference(string $class, string $field, int $id): bool
    {
        $alias = 'reference';
        $query = $this->em->createQuery(sprintf('SELECT %s FROM %s %s WHERE %s.%s = :target', $alias, $class, $alias, $alias, $field))
            ->setParameter('target', $id)->setMaxResults(1)->setLockMode(LockMode::PESSIMISTIC_WRITE);
        return $query->getOneOrNullResult() !== null;
    }
    private function lockHierarchy(): void
    {
        $this->em->createQuery('SELECT category FROM App\\Common\\Entity\\Category category ORDER BY category.id ASC')
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getResult();
    }
    private function revalidate(callable $callback, string $message): void
    {
        try { $callback(); } catch (BadRequestHttpException $error) { throw new ProposalConflictException($message, $error); }
    }
    /** @return array<string, mixed> */
    private function snapshot(Category $e): array { return ['id'=>$e->getId(),'uuid'=>$e->getUuid(),'name'=>$e->getName(),'slug'=>$e->getSlug(),'description'=>$e->getDescription(),'parent'=>$e->getParent()?->getId(),'sortOrder'=>$e->getSortOrder(),'enabled'=>$e->isEnabled()]; }
    /** @param array<string, mixed>|null $snapshot */
    private function hash(?array $snapshot): string { return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)); }
}
