<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Common\Entity\Tag;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Common\Service\TagWriteService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Agent-specific Tag policy: permissions, previews, conflicts and receipts. Domain writes live in TagWriteService. */
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
            $target = $this->writes->create($changes);
        } else {
            try { $target = $this->writes->find($identifier, true); }
            catch (\OutOfBoundsException $error) { throw new ProposalConflictException('Tag no longer exists. Prepare a new preview.', $error); }
            $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
            if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Tag changed since preview. Prepare a new preview.');
        }

        if ($action === 'delete') {
            $this->revalidate(fn () => $this->writes->assertUnused($target, true), 'Tag associations changed after preview. Prepare a new preview.');
            $id = (string) $target->getId();
            $this->writes->remove($target);
            try { $this->em->flush(); }
            catch (ForeignKeyConstraintViolationException $error) { throw new ProposalConflictException('Tag acquired an association after preview. Prepare a new preview.', $error); }
            return ['identifier' => $id, 'verified' => $this->em->find(Tag::class, $id) === null];
        }

        $this->writes->apply($target, $changes);
        $this->revalidate(fn () => $this->writes->assertUniqueSlug($target->getSlug(), $target->getId()), 'Tag slug was claimed after preview. Prepare a new preview.');
        $expected = array_intersect_key($this->snapshot($target), array_fill_keys(self::EXPOSED_FIELDS, true));
        $this->writes->persist($target);
        try { $this->em->flush(); }
        catch (UniqueConstraintViolationException $error) { throw new ProposalConflictException('Tag slug was claimed after preview. Prepare a new preview.', $error); }
        $this->em->refresh($target);
        return ['identifier' => (string) $target->getId(), 'verified' => array_intersect_key($this->snapshot($target), array_fill_keys(self::EXPOSED_FIELDS, true)) === $expected];
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

    /** @param array<string, mixed>|null $value */
    private function hash(?array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
