<?php

declare(strict_types=1);

namespace App\Trade\Service\Catalog;

/**
 * Immutable snapshot of a sellable Specification for pricing/order.
 * Trade owns this DTO; Store provides the data without exposing Store entities.
 */
final readonly class CatalogItem
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
        public int $price,
        public string $status,
        public bool $isDeleted,
        public int $productId,
        public string $productUuid,
        public string $productName,
        public bool $productIsDeleted,
        public string $productStatus,
        public ?string $storeUuid = null,
        public ?int $storeId = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isDeleted && $this->productStatus === 'active' && !$this->productIsDeleted;
    }
}
