<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Bounded, store-owned catalog projection for employee order entry. */
final class StaffOrderCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductAvailability $availability,
    ) {}

    /** @return array<string, mixed> */
    public function search(Store $store, int $page, string $query): array
    {
        $builder = $this->em->createQueryBuilder()->select('spec', 'product')->from(Specification::class, 'spec')->join('spec.product', 'product')
            ->where('product.store = :store')->andWhere('product.isDeleted = false')->andWhere('spec.isDeleted = false')
            ->andWhere('product.status = :active')->andWhere('spec.status = :active')->setParameter('active', 'active')->setParameter('store', $store);
        if ($query !== '') {
            $builder->andWhere("(LOWER(product.name) LIKE :query ESCAPE '!' OR LOWER(spec.name) LIKE :query ESCAPE '!')")
                ->setParameter('query', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%');
        }
        $count = clone $builder;
        $total = (int) $count->select('COUNT(spec.id)')->getQuery()->getSingleScalarResult();
        $specifications = $builder->orderBy('product.name', 'ASC')->addOrderBy('spec.sort', 'ASC')->addOrderBy('spec.id', 'ASC')
            ->setFirstResult(($page - 1) * 30)->setMaxResults(30)->getQuery()->getResult();
        $now = new \DateTimeImmutable();
        $items = array_map(function (Specification $specification) use ($now): array {
            $product = $specification->getProduct();
            if ($product === null) throw new HttpException(409, 'staff_order_item_changed');
            return ['uuid' => $specification->getUuid(), 'productName' => $product->getName(), 'name' => $specification->getName(),
                'price' => $specification->getPrice(), 'available' => !($product instanceof Product) || $this->availability->isAvailable($product, $now)];
        }, $specifications);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'hasNext' => $page * 30 < $total];
    }
}
