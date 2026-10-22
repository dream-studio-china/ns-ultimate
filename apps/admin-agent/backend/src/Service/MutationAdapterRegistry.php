<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Service;

use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class MutationAdapterRegistry
{
    /** @var array<string, MutationAdapter> */
    private array $adapters = [];

    /** @param iterable<MutationAdapter> $adapters */
    public function __construct(#[AutowireIterator('admin_agent.mutation_adapter')] iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $key = $adapter->entityKey();
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,119}$/D', $key) || isset($this->adapters[$key])) {
                throw new \LogicException('Invalid or duplicate assistant mutation adapter key.');
            }
            $this->adapters[$key] = $adapter;
        }
    }

    public function get(string $entity): MutationAdapter
    {
        return $this->adapters[$entity] ?? throw new BadRequestHttpException('This entity is not enabled for assistant mutations.');
    }
}
