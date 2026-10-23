<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Common\Entity\Category;
use App\Common\Entity\Content as CoreContent;
use App\Common\Entity\Tag;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Common\Entity\Content;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Agent-specific ns-ultimate binding. Reuses existing domain entities and validation without modifying them. */
final class ContentMutationAdapter implements MutationAdapter
{
    private const FIELDS = ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {}

    public function entityKey(): string { return 'Content'; }

    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Content management requires ROLE_ADMIN.');
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $target = $action === 'create' ? null : $this->findTarget($identifier);
        $before = $target === null ? null : $this->snapshot($target);
        if ($action !== 'delete') {
            $changes = $this->normalizeChanges($changes, $action === 'create');
            if ($target !== null && !$target instanceof Content && array_key_exists('sortOrder', $changes)) {
                throw new BadRequestHttpException('sortOrder is unavailable on this legacy Content subtype.');
            }
            $candidate = new Content('');
            if ($before !== null) $this->apply($candidate, array_intersect_key($before, array_flip(self::FIELDS)));
            $this->apply($candidate, $changes);
            $this->validate($candidate);
            $after = $this->snapshot($candidate);
        } else {
            $after = null;
        }
        $differences = [];
        // Emit differences in the adapter's declared field order rather than the
        // caller's key order. MySQL's JSON type reorders object keys when stored,
        // so relying on the incoming order makes restored previews differ from
        // the original preview on MySQL while matching on SQLite.
        $changedFields = $action === 'delete' ? self::FIELDS : array_intersect(self::FIELDS, array_keys($changes));
        foreach ($changedFields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($action !== 'update' || $old !== $new) $differences[] = ['property' => $field, 'before' => $old, 'after' => $new];
        }
        return ['identifier' => $target === null ? null : (string) $target->getId(),
            'title' => $before['title'] ?? $after['title'] ?? '', 'beforeHash' => $this->hash($before),
            'changes' => $changes, 'differences' => $differences];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $target = $action === 'create' ? new Content('') : $this->em->find(CoreContent::class, $identifier, LockMode::PESSIMISTIC_WRITE);
        if (!$target instanceof CoreContent) throw new ProposalConflictException('Content no longer exists. Prepare a new preview.');
        if ($action !== 'create') {
            $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
            if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Content changed since preview. Prepare a new preview.');
        }
        if ($action === 'delete') {
            $id = (string) $target->getId();
            $this->em->remove($target);
            $this->em->flush();
            return ['identifier' => $id, 'verified' => $this->em->find(CoreContent::class, $id) === null];
        }
        if (!$target instanceof Content && array_key_exists('sortOrder', $changes)) throw new BadRequestHttpException('sortOrder is unavailable on this legacy Content subtype.');
        $this->apply($target, $this->normalizeChanges($changes, $action === 'create'));
        $this->validate($target);
        $target->touch();
        $expected = array_intersect_key($this->snapshot($target), array_flip(self::FIELDS));
        $this->em->persist($target);
        $this->em->flush();
        $this->em->refresh($target);
        return ['identifier' => (string) $target->getId(),
            'verified' => array_intersect_key($this->snapshot($target), array_flip(self::FIELDS)) === $expected];
    }

    private function findTarget(?string $identifier): CoreContent
    {
        if ($identifier === null || !preg_match('/^(?:[1-9][0-9]{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/iD', $identifier)) {
            throw new BadRequestHttpException('An exact Content ID or UUID is required.');
        }
        $target = ctype_digit($identifier) ? $this->em->find(CoreContent::class, (int) $identifier)
            : $this->em->getRepository(CoreContent::class)->findOneBy(['uuid' => $identifier]);
        if (!$target instanceof CoreContent) throw new NotFoundHttpException('Content not found.');
        return $target;
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function normalizeChanges(array $changes, bool $create): array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== []) throw new BadRequestHttpException('Only title, body, category, tags, metadata and sortOrder may be changed.');
        if ($create && !array_key_exists('title', $changes)) throw new BadRequestHttpException('Creation requires title.');
        foreach ($changes as $field => $value) {
            $valid = match ($field) {
                'title' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255,
                'body' => $value === null || (is_string($value) && strlen($value) <= 32000),
                'category' => $value === null || (is_int($value) && $value > 0),
                'tags' => is_array($value) && array_is_list($value) && count($value) <= 50 && array_all($value, static fn ($id): bool => is_int($id) && $id > 0),
                'metadata' => $value === null || (is_array($value) && ($value === [] || !array_is_list($value)) && strlen(json_encode($value, JSON_THROW_ON_ERROR)) <= 8000),
                'sortOrder' => is_int($value) && $value >= -2147483648 && $value <= 2147483647,
                default => false,
            };
            if (!$valid) throw new BadRequestHttpException('Invalid Content field: '.$field.'.');
        }
        if (isset($changes['tags'])) {
            $changes['tags'] = array_values(array_unique($changes['tags']));
            sort($changes['tags'], SORT_NUMERIC);
        }
        return $changes;
    }

    /** @param array<string, mixed> $changes */
    private function apply(CoreContent $target, array $changes): void
    {
        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'title': $target->setTitle($value); break;
                case 'body': $target->setBody($value); break;
                case 'metadata': $target->setMetadata($value); break;
                case 'sortOrder':
                    if ($target instanceof Content && $value !== null) $target->setSortOrder($value);
                    break;
                case 'category':
                    $category = $value === null ? null : $this->em->find(Category::class, $value);
                    if ($value !== null && !$category instanceof Category) throw new BadRequestHttpException('Content category not found.');
                    $target->setCategory($category);
                    break;
                case 'tags':
                    $tags = [];
                    foreach ($value as $id) {
                        $tag = $this->em->find(Tag::class, $id);
                        if (!$tag instanceof Tag) throw new BadRequestHttpException('Content tag not found.');
                        $tags[] = $tag;
                    }
                    foreach ($target->getTags()->toArray() as $tag) $target->removeTag($tag);
                    foreach ($tags as $tag) $target->addTag($tag);
                    break;
            }
        }
    }

    private function validate(CoreContent $content): void
    {
        $violations = $this->validator->validate($content);
        if (count($violations) > 0) throw new BadRequestHttpException((string) $violations);
    }

    /** @return array<string, mixed> */
    private function snapshot(CoreContent $target): array
    {
        $tags = array_map(static fn (Tag $tag): ?int => $tag->getId(), $target->getTags()->toArray());
        sort($tags, SORT_NUMERIC);
        return ['id' => $target->getId(), 'uuid' => $target->getUuid(), 'title' => $target->getTitle(),
            'body' => $target->getBody(), 'category' => $target->getCategory()?->getId(), 'tags' => $tags,
            'metadata' => $target->getMetadata(), 'sortOrder' => $target instanceof Content ? $target->getSortOrder() : null,
            'updatedAt' => $target->getUpdatedAt()?->format('Y-m-d\TH:i:s.uP')];
    }

    /** @param array<string, mixed>|null $snapshot */
    private function hash(?array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
