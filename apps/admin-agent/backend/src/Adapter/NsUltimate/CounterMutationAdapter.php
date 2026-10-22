<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Store\Entity\Store;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Agent-specific Counter mutation policy; metadata is deliberately never writable or disclosed. */
final class CounterMutationAdapter implements MutationAdapter
{
    private const FIELDS = ['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled'];

    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $authorization) {}
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
        $target = $action === 'create' ? null : $this->find($identifier);
        if ($action === 'delete') $this->assertUnreferenced($target);
        $normalized = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create');
        if ($target && array_key_exists('storeUuid', $normalized)) throw new BadRequestHttpException('A Counter cannot be moved to another store.');
        $before = $target ? $this->snapshot($target) : null;
        $after = $before ?? ['storeUuid' => '', 'code' => '', 'name' => '', 'capacity' => null, 'sort' => 0, 'enabled' => true];
        if ($action !== 'delete') $after = array_replace($after, $normalized);
        if ($action !== 'delete') {
            if ($action === 'create') $this->assertStore($after['storeUuid']);
            $this->assertUnique($after['storeUuid'], $after['code'], $target?->getId());
        }
        $fields = $action === 'delete' ? self::FIELDS : array_values(array_intersect(self::FIELDS, array_keys($normalized)));
        $differences = [];
        foreach ($fields as $field) if ($action === 'delete' || $action !== 'update' || ($before[$field] ?? null) !== ($after[$field] ?? null)) $differences[] = ['property' => $field, 'before' => $before[$field] ?? null, 'after' => $action === 'delete' ? null : ($after[$field] ?? null)];
        $title = $action === 'delete' ? $before['storeUuid'].' / '.$before['name'] : ($before['name'] ?? $after['name']);
        return ['identifier' => $target ? (string) $target->getId() : null, 'title' => $title, 'beforeHash' => $this->hash($before), 'changes' => $normalized, 'differences' => $differences];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        if ($action === 'delete' && $changes !== []) throw new BadRequestHttpException('Counter deletion does not accept fields.');
        $changes = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create');
        if ($action === 'create') {
            $this->assertStore($changes['storeUuid'], true);
            $target = (new Counter())->setStoreUuid($changes['storeUuid'])->setCode($changes['code'])->setName($changes['name']);
            $this->apply($target, $changes);
            $this->assertUnique($target->getStoreUuid(), $target->getCode(), null, true);
            $this->em->persist($target);
            try { $this->em->flush(); }
            catch (ConstraintViolationException $error) { throw new ProposalConflictException('Counter code or store scope changed after preview. Prepare a new preview.', $error); }
            $expected = $this->snapshot($target);
            $this->em->refresh($target);
            return ['identifier' => (string) $target->getId(), 'verified' => $this->canonicalize($this->snapshot($target)) === $this->canonicalize($expected)];
        }
        try { $target = $this->find($identifier, true); }
        catch (NotFoundHttpException $e) { throw new ProposalConflictException('Counter no longer exists. Prepare a new preview.', $e); }
        $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
        if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Counter changed since preview. Prepare a new preview.');
        if ($action === 'delete') {
            $this->assertUnreferenced($target, true);
            $id = (string) $target->getId();
            $this->em->remove($target);
            try { $this->em->flush(); }
            catch (ConstraintViolationException $error) { throw new ProposalConflictException('Counter acquired a binding after preview and cannot be deleted. Prepare a new preview.', $error); }
            return ['identifier' => $id, 'verified' => $this->em->find(Counter::class, $id) === null];
        }
        if (array_key_exists('storeUuid', $changes)) throw new BadRequestHttpException('A Counter cannot be moved to another store.');
        $this->apply($target, $changes);
        $this->assertUnique($target->getStoreUuid(), $target->getCode(), $target->getId(), true);
        $expected = $this->snapshot($target); $id = (string) $target->getId();
        try { $this->em->flush(); }
        catch (ConstraintViolationException $error) { throw new ProposalConflictException('Counter code was claimed after preview. Prepare a new preview.', $error); }
        $this->em->refresh($target);
        return ['identifier' => $id, 'verified' => $this->canonicalize($this->snapshot($target)) === $this->canonicalize($expected)];
    }

    private function normalize(array $changes, bool $create): array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== [] || ($create && array_diff(['storeUuid', 'code', 'name'], array_keys($changes)) !== [])) throw new BadRequestHttpException('Invalid Counter fields.');
        foreach ($changes as $field => $value) {
            $valid = match ($field) {
                'storeUuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value),
                'code' => is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= 50,
                'name' => is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= 120,
                'capacity' => $value === null || (is_int($value) && $value >= 1 && $value <= 2147483647),
                'sort' => is_int($value) && $value >= 0 && $value <= 2147483647,
                'enabled' => is_bool($value), default => false,
            };
            if (!$valid) throw new BadRequestHttpException('Invalid Counter field: '.$field.'.');
            if (in_array($field, ['code', 'name'], true)) $changes[$field] = trim($value);
            if ($field === 'storeUuid') $changes[$field] = strtolower($value);
        }
        return $changes;
    }
    private function apply(Counter $target, array $changes): void
    {
        if (array_key_exists('code', $changes)) $target->setCode($changes['code']);
        if (array_key_exists('name', $changes)) $target->setName($changes['name']);
        if (array_key_exists('capacity', $changes)) $target->setCapacity($changes['capacity']);
        if (array_key_exists('sort', $changes)) $target->setSort($changes['sort']);
        if (array_key_exists('enabled', $changes)) $target->setEnabled($changes['enabled']);
    }
    private function find(?string $identifier, bool $lock = false): Counter
    {
        if ($identifier === null || !preg_match('/^(?:[1-9][0-9]{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $identifier)) throw new BadRequestHttpException('An exact Counter ID or UUID is required.');
        $target = ctype_digit($identifier) ? $this->em->find(Counter::class, (int) $identifier, $lock ? LockMode::PESSIMISTIC_WRITE : null) : $this->em->getRepository(Counter::class)->findOneBy(['uuid' => strtolower($identifier)]);
        if (!$target instanceof Counter) throw new NotFoundHttpException('Counter not found.'); return $target;
    }
    private function assertStore(string $uuid, bool $commit = false): void
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $uuid]);
        if (!$store instanceof Store) {
            if ($commit) throw new ProposalConflictException('Counter store no longer exists. Prepare a new preview.');
            throw new BadRequestHttpException('Counter store must exist and be active.');
        }
        if ($commit) {
            try { $this->em->refresh($store, LockMode::PESSIMISTIC_WRITE); }
            catch (\Doctrine\ORM\EntityNotFoundException $error) { throw new ProposalConflictException('Counter store no longer exists. Prepare a new preview.', $error); }
        }
        if (!$store->isActive()) {
            if ($commit) throw new ProposalConflictException('Counter store is no longer active. Prepare a new preview.');
            throw new BadRequestHttpException('Counter store must exist and be active.');
        }
    }
    private function assertUnique(string $storeUuid, string $code, ?int $id, bool $commit = false): void
    {
        $other = $this->em->getRepository(Counter::class)->findOneBy(['storeUuid' => $storeUuid, 'code' => $code]);
        if ($other instanceof Counter && $other->getId() !== $id) {
            if ($commit) throw new ProposalConflictException('Counter code was claimed after preview. Prepare a new preview.');
            throw new BadRequestHttpException('Counter code is already in use in this store.');
        }
    }
    private function assertUnreferenced(Counter $target, bool $commit = false): void
    {
        if ($this->em->getRepository(OrderCounter::class)->count(['counter' => $target])) {
            if ($commit) throw new ProposalConflictException('Counter acquired a binding after preview and cannot be deleted. Prepare a new preview.');
            throw new BadRequestHttpException('Counter has order bindings and cannot be deleted.');
        }
    }
    private function snapshot(Counter $target): array
    {
        return ['id'=>$target->getId(),'uuid'=>$target->getUuid(),'storeUuid'=>$target->getStoreUuid(),'code'=>$target->getCode(),'name'=>$target->getName(),'capacity'=>$target->getCapacity(),'sort'=>$target->getSort(),'metadata'=>$target->getMetadata(),'enabled'=>$target->getEnabled(),'createdAt'=>$target->getCreatedAt()->format('Y-m-d H:i:s'),'updatedAt'=>$target->getUpdatedAt()?->format('Y-m-d H:i:s')];
    }
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
