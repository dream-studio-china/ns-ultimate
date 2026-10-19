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
    private int $sort = 0;

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): self
    {
        $this->sort = $sort;
        $this->touch();

        return $this;
    }
}
