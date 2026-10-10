<?php

declare(strict_types=1);

namespace App\Store\Entity;

use App\Core\Utils\UUID;
use App\Store\Repository\ProductCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
#[ORM\Table(name: 'store_product_category')]
#[ORM\UniqueConstraint(name: 'uniq_store_product_category_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_product_category_scope_slug', columns: ['scope_key', 'slug'])]
#[ORM\Index(name: 'idx_store_product_category_store_enabled', columns: ['store_id', 'enabled'])]
class ProductCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Store::class)]
    #[ORM\JoinColumn(name: 'store_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Store $store = null;

    #[ORM\Column(name: 'scope_key', type: 'string', length: 45)]
    private string $scopeKey = 'global';

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 100)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, ProductCategory> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $name = '', string $slug = '')
    {
        $this->uuid = UUID::v4();
        $this->name = $name;
        $this->slug = $slug;
        $this->children = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStore(): ?Store { return $this->store; }
    public function getScopeKey(): string { return $this->scopeKey; }
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getParent(): ?self { return $this->parent; }
    /** @return Collection<int, ProductCategory> */
    public function getChildren(): Collection { return $this->children; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function setStore(?Store $store): self
    {
        if ($this->parent !== null && $this->parent->getStore()?->getUuid() !== $store?->getUuid()) {
            throw new \InvalidArgumentException('A category and its parent must have the same store scope.');
        }
        if ($this->children->count() > 0 && $this->store?->getUuid() !== $store?->getUuid()) {
            throw new \InvalidArgumentException('A category with children cannot change store scope.');
        }
        $this->store = $store;
        $this->scopeKey = $store === null ? 'global' : 'store:'.$store->getUuid();
        return $this->touch();
    }

    public function setName(string $name): self { $this->name = $name; return $this->touch(); }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this->touch(); }
    public function setDescription(?string $description): self { $this->description = $description; return $this->touch(); }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this->touch(); }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }

    public function setParent(?self $parent): self
    {
        if ($this->parent === $parent) {
            return $this;
        }
        if ($parent === $this) {
            throw new \InvalidArgumentException('A product category cannot be its own parent.');
        }
        if ($parent !== null && $parent->getStore()?->getUuid() !== $this->store?->getUuid()) {
            throw new \InvalidArgumentException('Parent category must have the same store scope.');
        }
        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $this) {
                throw new \InvalidArgumentException('A product category cannot be its own ancestor.');
            }
        }
        $previousParent = $this->parent;
        $this->parent = $parent;
        $previousParent?->children->removeElement($this);
        if ($parent !== null && !$parent->children->contains($this)) {
            $parent->children->add($this);
        }
        return $this->touch();
    }

    public function addChild(self $child): self
    {
        if (!$this->children->contains($child)) {
            $child->setParent($this);
            $this->children->add($child);
        }
        return $this;
    }

    public function removeChild(self $child): self
    {
        if ($child->getParent() === $this) {
            $child->setParent(null);
        }
        return $this;
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
