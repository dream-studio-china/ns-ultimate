<?php

declare(strict_types=1);

namespace App\Trade\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Trade\Repository\OrderItemRepository::class)]
#[ORM\Table(name: 'trade_order_item')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_trade_order_item_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_trade_order_item_spec_uuid', columns: ['specification_uuid'])]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Order $order = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $specificationUuid = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $specificationTitle = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $quantity = 1;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $unitPrice = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $price = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $cost = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $profit = 0;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $specSnapshot = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $productSnapshot = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        $title = $this->specificationTitle ?? 'N/A';
        return sprintf('%s x%d @%d', $title, $this->quantity, $this->unitPrice);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getSpecificationUuid(): ?string
    {
        return $this->specificationUuid;
    }

    public function setSpecificationUuid(?string $specificationUuid): self
    {
        $this->specificationUuid = $specificationUuid;
        return $this;
    }

    public function getSpecificationTitle(): ?string
    {
        return $this->specificationTitle;
    }

    public function setSpecificationTitle(?string $specificationTitle): self
    {
        $this->specificationTitle = $specificationTitle;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(int $unitPrice): self
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function setCost(int $cost): self
    {
        $this->cost = $cost;
        $this->profit = $this->price - $cost;
        return $this;
    }

    public function getProfit(): int
    {
        return $this->profit;
    }

    public function setProfit(int $profit): self
    {
        $this->profit = $profit;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSpecSnapshot(): ?array
    {
        return $this->specSnapshot;
    }

    /**
     * @param array<string, mixed>|null $specSnapshot
     */
    public function setSpecSnapshot(?array $specSnapshot): self
    {
        $this->specSnapshot = $specSnapshot;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProductSnapshot(): ?array
    {
        return $this->productSnapshot;
    }

    /**
     * @param array<string, mixed>|null $productSnapshot
     */
    public function setProductSnapshot(?array $productSnapshot): self
    {
        $this->productSnapshot = $productSnapshot;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }

        // Snapshots and title are set explicitly by OrderService/BasePriceCalculator;
        // keep prePersist as price calc only for historical data safety.
        if ($this->specificationTitle === null && is_array($this->specSnapshot) && isset($this->specSnapshot['name'])) {
            $this->specificationTitle = (string) $this->specSnapshot['name'];
        }

        $this->price = $this->unitPrice * $this->quantity;
    }
}
