<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Service;

use App\Common\Entity\Content;
use App\Common\Entity\Tag;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reusable Tag write rules for explicitly registered application adapters,
 * including batch creation. The standard admin Tag controller remains core-owned
 * and intentionally does not apply this stricter assistant schema.
 *
 * Transaction ownership: this service never begins, commits, or rolls back a
 * transaction and never flushes. The caller owns the transaction and flush boundary;
 * persist() and remove() only stage the entity on the current EntityManager.
 */
final class TagWriteService
{
    private const ACTIONS = ['create', 'update', 'delete'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Action-specific field descriptors. These are the source of truth for both
     * normalization and external capability documentation; callers must not keep a
     * second domain field list.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fieldSchema(string $action): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported Tag action.');
        }
        if ($action === 'delete') {
            return [];
        }

        // The existing frontend descriptor defines the same fields for create and
        // update; requiredOnCreate is only enforced by normalize() on create.
        return [
            'name' => ['type' => 'string', 'maxLength' => 255, 'requiredOnCreate' => true, 'nonEmpty' => true],
            'slug' => ['type' => 'string', 'maxLength' => 255, 'requiredOnCreate' => true, 'nonEmpty' => true],
            'color' => ['type' => 'color', 'nullable' => true],
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function normalize(string $action, array $changes, bool $partial = false): array
    {
        $schema = self::fieldSchema($action);
        if ($action === 'delete') {
            if ($changes !== []) {
                throw new \InvalidArgumentException('Tag deletion does not accept fields.');
            }

            return [];
        }
        if (array_diff(array_keys($changes), array_keys($schema)) !== []) {
            throw new \InvalidArgumentException('Invalid Tag fields.');
        }
        if (!$partial) {
            if ($changes === []) {
                throw new \InvalidArgumentException('Invalid Tag fields.');
            }
            if ($action === 'create') {
                foreach ($schema as $field => $rule) {
                    if (($rule['requiredOnCreate'] ?? false) && !array_key_exists($field, $changes)) {
                        throw new \InvalidArgumentException('Invalid Tag fields.');
                    }
                }
            }
        }

        $normalized = [];
        foreach ($changes as $field => $value) {
            $normalized[$field] = $this->normalizeValue($field, $value, $schema[$field]);
        }

        return $normalized;
    }

    public function find(?string $identifier, bool $lock = false): Tag
    {
        if ($identifier === null
            || !preg_match('/^(?:[1-9][0-9]{0,9}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/iD', $identifier)
            || (ctype_digit($identifier) && (int) $identifier > 2147483647)) {
            throw new \InvalidArgumentException('An exact Tag ID or UUID is required.');
        }

        $tag = ctype_digit($identifier)
            ? $this->em->find(Tag::class, (int) $identifier, $lock ? LockMode::PESSIMISTIC_WRITE : null)
            : $this->em->getRepository(Tag::class)->findOneBy(['uuid' => strtolower($identifier)]);
        if (!$tag instanceof Tag) {
            throw new \OutOfBoundsException('Tag not found.');
        }

        return $tag;
    }

    public function assertUniqueSlug(string $slug, ?int $ignoreId = null): void
    {
        $other = $this->em->getRepository(Tag::class)->findOneBy(['slug' => $slug]);
        if ($other instanceof Tag && $other->getId() !== $ignoreId) {
            throw new \InvalidArgumentException('Tag slug is already in use.');
        }
    }

    public function assertUnused(Tag $tag, bool $lockReferences = false): void
    {
        // Join-table reference query prevents deletion from silently detaching tags
        // from content.
        $query = $this->em->createQuery('SELECT c FROM '.Content::class.' c JOIN c.tags t WHERE t.id = :tag')
            ->setParameter('tag', $tag->getId())
            ->setMaxResults(1);
        if ($lockReferences) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        if ($query->getOneOrNullResult() !== null) {
            throw new \InvalidArgumentException('Tag is assigned to content and cannot be deleted.');
        }
    }

    /** @param array<string, mixed> $changes */
    public function create(array $changes): Tag
    {
        $tag = new Tag($changes['name'], $changes['slug']);
        $this->apply($tag, $changes);

        return $tag;
    }

    /** @param array<string, mixed> $changes */
    public function apply(Tag $tag, array $changes): void
    {
        if (array_key_exists('name', $changes)) {
            $tag->setName($changes['name']);
        }
        if (array_key_exists('slug', $changes)) {
            $tag->setSlug($changes['slug']);
        }
        if (array_key_exists('color', $changes)) {
            $tag->setColor($changes['color']);
        }
    }

    public function persist(Tag $tag): void
    {
        $this->em->persist($tag);
    }

    public function remove(Tag $tag): void
    {
        $this->em->remove($tag);
    }

    /** @param array<string, mixed> $rule */
    private function normalizeValue(string $field, mixed $value, array $rule): mixed
    {
        if ($value === null) {
            if (($rule['nullable'] ?? false) === true) {
                return null;
            }
            throw new \InvalidArgumentException('Invalid Tag field: '.$field.'.');
        }

        $trimForValidation = ($rule['trimForValidation'] ?? false) === true;
        $checked = $trimForValidation && is_string($value) ? trim($value) : $value;
        $valid = match ($rule['type']) {
            'string' => is_string($checked)
                && mb_strlen($checked) <= $rule['maxLength']
                && (!($rule['nonEmpty'] ?? false) || trim($checked) !== ''),
            'color' => is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) === 1,
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('Invalid Tag field: '.$field.'.');
        }

        return $checked;
    }
}
