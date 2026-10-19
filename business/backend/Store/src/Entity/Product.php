<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Entity;

use App\Store\Entity\Product as CoreProduct;
use NsUltimate\Business\Store\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;

/** Project-owned Product subtype used to verify core entity extension. */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product extends CoreProduct
{
    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        $this->touch();

        return $this;
    }
}
