<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Adapter;

use App\Store\Entity\Product as CoreProduct;
use App\Store\Service\Catalog\StoreCatalogResolver;
use App\Trade\Service\Catalog\CatalogItem;
use App\Trade\Service\Catalog\CatalogResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Business\Store\Service\ProductAvailability;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** Reuse the Trade catalog port; never trust browser availability checks or submitted prices. */
#[AsDecorator(decorates: StoreCatalogResolver::class)]
final readonly class AvailableCatalogResolver implements CatalogResolverInterface
{
    public function __construct(
        #[AutowireDecorated] private CatalogResolverInterface $inner,
        private EntityManagerInterface $em,
        private ProductAvailability $availability,
    ) {}

    public function resolveForPricing(int|string $specificationId, ?string $storeCode): ?CatalogItem
    {
        $item = $this->inner->resolveForPricing($specificationId, $storeCode);
        if ($item === null) return null;
        $product = $this->em->getRepository(CoreProduct::class)->findOneBy(['uuid' => $item->productUuid]);
        if ($product instanceof Product && !$this->availability->isAvailable($product, new \DateTimeImmutable())) return null;
        return $item;
    }
}
