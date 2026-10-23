<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;

/**
 * Reusable Counter write rules for explicitly registered application adapters,
 * plus an existence-only Store lookup reused by standard administration. Standard
 * administration retains its original controller and serializer validation profile.
 *
 * Transaction ownership: this service never begins, commits, or rolls back a
 * transaction and never flushes. The caller owns the transaction and flush boundary;
 * persist() and remove() only stage the entity on the current EntityManager.
 */
final class CounterWriteService
{
    private const ACTIONS = ['create', 'update', 'delete'];
    private const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Action-specific field descriptors. These are the source of truth for both
     * normalization and external capability documentation; callers must not keep a
     * second domain field list. Store assignment is a create-only field.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fieldSchema(string $action): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported Counter action.');
        }
        if ($action === 'delete') {
            return [];
        }

        $mutable = [
            'code' => ['type' => 'string', 'maxLength' => 50, 'requiredOnCreate' => true, 'nonEmpty' => true, 'trimForValidation' => true],
            'name' => ['type' => 'string', 'maxLength' => 120, 'requiredOnCreate' => true, 'nonEmpty' => true, 'trimForValidation' => true],
            'capacity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'nullable' => true],
            'sort' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647],
            'enabled' => ['type' => 'boolean'],
        ];

        if ($action === 'create') {
            return [
                'storeUuid' => ['type' => 'uuid', 'pattern' => self::UUID_PATTERN, 'patternFlags' => 'i', 'requiredOnCreate' => true],
                ...$mutable,
            ];
        }

        return $mutable;
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
                throw new \InvalidArgumentException('Counter deletion does not accept fields.');
            }

            return [];
        }
        if ($action !== 'create' && array_key_exists('storeUuid', $changes)) {
            throw new \InvalidArgumentException('A Counter cannot be moved to another store.');
        }
        if (array_diff(array_keys($changes), array_keys($schema)) !== []) {
            throw new \InvalidArgumentException('Invalid Counter fields.');
        }
        if (!$partial) {
            if ($changes === []) {
                throw new \InvalidArgumentException('Invalid Counter fields.');
            }
            if ($action === 'create') {
                foreach ($schema as $field => $rule) {
                    if (($rule['requiredOnCreate'] ?? false) && !array_key_exists($field, $changes)) {
                        throw new \InvalidArgumentException('Invalid Counter fields.');
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

    public function find(?string $identifier, bool $lock = false): Counter
    {
        if ($identifier === null
            || !preg_match('/^(?:[1-9][0-9]{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $identifier)) {
            throw new \InvalidArgumentException('An exact Counter ID or UUID is required.');
        }

        $counter = ctype_digit($identifier)
            ? $this->em->find(Counter::class, (int) $identifier, $lock ? LockMode::PESSIMISTIC_WRITE : null)
            : $this->em->getRepository(Counter::class)->findOneBy(['uuid' => strtolower($identifier)]);
        if (!$counter instanceof Counter) {
            throw new \OutOfBoundsException('Counter not found.');
        }

        return $counter;
    }

    /**
     * Existence-only Store lookup. The caller owns lifecycle policy such as whether
     * an inactive store is acceptable; no active check is embedded here.
     */
    public function findStore(string $storeUuid, bool $lock = false): ?Store
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        if (!$store instanceof Store) {
            return null;
        }
        if ($lock) {
            try {
                $this->em->refresh($store, LockMode::PESSIMISTIC_WRITE);
            } catch (EntityNotFoundException) {
                return null;
            }
        }

        return $store;
    }

    /** Existence-only Store lookup that fails with a domain validation error. */
    public function requireStore(string $storeUuid, bool $lock = false): Store
    {
        $store = $this->findStore($storeUuid, $lock);
        if (!$store instanceof Store) {
            throw new \InvalidArgumentException('Counter store must exist.');
        }

        return $store;
    }

    public function assertUnique(string $storeUuid, string $code, ?int $ignoreId = null): void
    {
        $other = $this->em->getRepository(Counter::class)->findOneBy(['storeUuid' => $storeUuid, 'code' => $code]);
        if ($other instanceof Counter && $other->getId() !== $ignoreId) {
            throw new \InvalidArgumentException('Counter code is already in use in this store.');
        }
    }

    public function assertUnreferenced(Counter $counter): void
    {
        if ($this->em->getRepository(OrderCounter::class)->count(['counter' => $counter])) {
            throw new \InvalidArgumentException('Counter has order bindings and cannot be deleted.');
        }
    }

    /** @param array<string, mixed> $changes */
    public function create(array $changes): Counter
    {
        $counter = (new Counter())->setStoreUuid($changes['storeUuid']);
        unset($changes['storeUuid']);
        $this->apply($counter, $changes);

        return $counter;
    }

    /** @param array<string, mixed> $changes */
    public function apply(Counter $counter, array $changes): void
    {
        if (array_key_exists('storeUuid', $changes)) {
            throw new \InvalidArgumentException('A Counter cannot be moved to another store.');
        }
        if (array_key_exists('code', $changes)) {
            $counter->setCode($changes['code']);
        }
        if (array_key_exists('name', $changes)) {
            $counter->setName($changes['name']);
        }
        if (array_key_exists('capacity', $changes)) {
            $counter->setCapacity($changes['capacity']);
        }
        if (array_key_exists('sort', $changes)) {
            $counter->setSort($changes['sort']);
        }
        if (array_key_exists('enabled', $changes)) {
            $counter->setEnabled($changes['enabled']);
        }
    }

    public function persist(Counter $counter): void
    {
        $this->em->persist($counter);
    }

    public function remove(Counter $counter): void
    {
        $this->em->remove($counter);
    }

    /** @param array<string, mixed> $rule */
    private function normalizeValue(string $field, mixed $value, array $rule): mixed
    {
        if ($value === null) {
            if (($rule['nullable'] ?? false) === true) {
                return null;
            }
            throw new \InvalidArgumentException('Invalid Counter field: '.$field.'.');
        }

        $trimForValidation = ($rule['trimForValidation'] ?? false) === true;
        $checked = $trimForValidation && is_string($value) ? trim($value) : $value;
        $valid = match ($rule['type']) {
            'string' => is_string($checked)
                && mb_strlen($checked) <= $rule['maxLength']
                && (!($rule['nonEmpty'] ?? false) || trim($checked) !== ''),
            'integer' => is_int($value)
                && $value >= $rule['minimum']
                && $value <= $rule['maximum'],
            'boolean' => is_bool($value),
            'uuid' => is_string($value)
                && preg_match('/'.$rule['pattern'].'/'.$rule['patternFlags'].'D', $value) === 1,
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('Invalid Counter field: '.$field.'.');
        }

        return $rule['type'] === 'uuid' ? strtolower($checked) : $checked;
    }
}
