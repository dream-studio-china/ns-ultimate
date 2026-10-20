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

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $recommendationLevel = 0;

    #[ORM\Column(name: 'is_sold_out', type: 'boolean', options: ['default' => false])]
    private bool $isSoldout = false;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

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

    public function getRecommendationLevel(): int
    {
        return $this->recommendationLevel;
    }

    public function setRecommendationLevel(int $recommendationLevel): self
    {
        $this->recommendationLevel = $recommendationLevel;
        $this->touch();

        return $this;
    }

    public function isSoldout(): bool
    {
        return $this->isSoldout;
    }

    public function getIsSoldout(): bool
    {
        return $this->isSoldout;
    }

    public function setIsSoldout(bool $isSoldout): self
    {
        $this->isSoldout = $isSoldout;
        $this->touch();

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSettings(): ?array
    {
        return $this->settings;
    }

    /** @param array<string, mixed>|null $settings */
    public function setSettings(?array $settings): self
    {
        $this->settings = $settings;
        $this->touch();

        return $this;
    }
}
