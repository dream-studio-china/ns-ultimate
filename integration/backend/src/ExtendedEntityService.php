<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use App\Core\Service\BaseService;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Integration\Backend\Doctrine\EntityExtensionQueryBuilderFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reuses core CRUD while adapting explicitly declared extension ordering.
 *
 * @template TEntity of object
 * @extends BaseService<TEntity>
 */
abstract class ExtendedEntityService extends BaseService
{
    /** @param class-string<TEntity> $rootClass */
    public function __construct(
        ContainerInterface $container,
        string $rootClass,
        private readonly EntityExtensionQueryBuilderFactory $extensionQueries,
    ) {
        parent::__construct($container, $rootClass);
        $this->qbFactory = $extensionQueries;
    }

    public function list(mixed $object = null, mixed $order = null, bool $disableRequest = true): mixed
    {
        $rootClass = $this->entityClass;
        $rootAlias = 'entity';
        if ($object instanceof QueryBuilder) {
            // Caller-provided queries bypass the query builder factory.
            $this->extensionQueries->joinExtensions($object);
            $rootClass = $object->getRootEntities()[0];
            $rootAlias = $object->getRootAliases()[0];
        }

        if (is_array($order)) {
            $mappedOrder = [];
            foreach ($order as $field => $direction) {
                $mappedOrder[$this->extensionQueries->orderExpression($field, $rootClass, $rootAlias)] = $direction;
            }
            $order = $mappedOrder;
        }

        $request = $disableRequest ? null : $this->getCurrentRequest();
        $originalOrder = $request?->query->get('@order');
        if (is_string($originalOrder)) {
            $request->query->set('@order', $this->extensionQueries->orderExpression($originalOrder, $rootClass, $rootAlias));
        }

        try {
            return parent::list($object, $order, $disableRequest);
        } finally {
            if (is_string($originalOrder)) {
                $request->query->set('@order', $originalOrder);
            }
        }
    }
}
