<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\Product as CoreProduct;
use App\Store\Service\ProductServiceInterface;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Store\Entity\Product> */
final class ProductService extends BaseService implements ProductServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, CoreProduct::class);
        $this->qbFactory = new ProductQueryBuilderFactory($this->em);
    }

    public function new(): Product
    {
        return new Product();
    }

    public function list(mixed $object = null, mixed $order = null, bool $disableRequest = true): mixed
    {
        if ($object instanceof QueryBuilder) {
            // Caller-provided queries bypass the service's query builder factory.
            ProductQueryBuilderFactory::joinExtension($object);
        }

        if (is_array($order)) {
            $mappedOrder = [];
            foreach ($order as $field => $direction) {
                $mappedOrder[$field === 'entity.sort' ? 'storeProductExtension.sort' : $field] = $direction;
            }
            $order = $mappedOrder;
        }

        $request = $disableRequest ? null : $this->getCurrentRequest();
        $originalOrder = $request?->query->get('@order');
        if (!$disableRequest && is_string($originalOrder)) {
            $request->query->set('@order', preg_replace(
                '/(?<![\w.])entity\.sort\b/',
                'storeProductExtension.sort',
                $originalOrder,
            ));
        }

        try {
            return parent::list($object, $order, $disableRequest);
        } finally {
            if (!$disableRequest && is_string($originalOrder)) {
                $request->query->set('@order', $originalOrder);
            }
        }
    }
}
