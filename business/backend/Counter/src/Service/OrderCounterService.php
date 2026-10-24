<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<OrderCounter> */
final class OrderCounterService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, OrderCounter::class);
    }

    /** @param array<string, mixed>|null $data */
    public function update(mixed $object, ?array $data = null, bool $noFlush = false): object|false
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            return parent::update($object, $data, $noFlush);
        }
        if ($noFlush) {
            throw new \LogicException('Deferred order counter writes require an explicit transaction.');
        }
        // Also covers partial CRUD creates, which bypass the controller's batch transaction.
        return $this->em->wrapInTransaction(fn (): object|false => parent::update($object, $data));
    }
}
