<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Service;

use NsUltimate\Apps\AdminAgent\Backend\Contract\BatchCreateAdapter;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BatchCreateAdapterRegistry
{
    /** @var array<string, BatchCreateAdapter> */
    private array $adapters = [];

    /** @param iterable<BatchCreateAdapter> $adapters */
    public function __construct(#[AutowireIterator('admin_agent.batch_create_adapter')] iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $key = $adapter->entityKey();
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,119}$/D', $key) || isset($this->adapters[$key])) {
                throw new \LogicException('Invalid or duplicate assistant batch create adapter key.');
            }
            $this->adapters[$key] = $adapter;
        }
    }

    public function get(string $entity): BatchCreateAdapter
    {
        return $this->adapters[$entity] ?? throw new BadRequestHttpException('This entity is not enabled for assistant batch creation.');
    }

    /** @return array<string, BatchCreateAdapter> Explicitly selected adapters keyed by entity. */
    public function all(): array
    {
        return $this->adapters;
    }
}
