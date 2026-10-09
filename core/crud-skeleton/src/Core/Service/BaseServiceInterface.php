<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Query\DqlExpression;
use Doctrine\ORM\QueryBuilder;

/**
 * @template TEntity of object
 */
interface BaseServiceInterface
{
    /**
     * Find entity by id or criteria or execute a QueryBuilder to return single result.
     * @param TEntity|int|string|array<string, mixed>|QueryBuilder|DqlExpression $object
     * @return TEntity|null
     */
    public function get($object, bool $directly = false);

    /**
     * List entities or return a QueryBuilder. When $disableRequest is false, the service may consult current Request.
     * @param mixed|null $object
     * @param mixed|null $order
     * @return mixed  array|QueryBuilder|ArrayCollection
     */
    public function list($object = null, $order = null, bool $disableRequest = true);

    /**
     * Create a new instance of the entity managed by the service.
     * @return TEntity
     */
    public function new();

    /**
     * Update an entity with provided data (may persist and flush).
     * @param mixed $object
     * @param array<string, mixed>|null $data
     * @param bool $noFlush When true, persist but do not call flush(). Caller is responsible for flushing.
     * @return object|false
     */
    public function update($object, ?array $data = null, bool $noFlush = false);

    /**
     * Remove the given entity.
     * @param TEntity|int|string|array<string, mixed> $object
     */
    public function remove($object): bool;

    /**
     * Run the callback inside a DB transaction (all-or-nothing semantics).
     * @param callable(\Doctrine\ORM\EntityManagerInterface): mixed $fn
     * @return mixed
     */
    public function wrapInTransaction(callable $fn): mixed;

}
