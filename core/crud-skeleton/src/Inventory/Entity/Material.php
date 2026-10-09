<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\MaterialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MaterialRepository::class)]
#[ORM\Table(name: 'inventory_material')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_material_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_material_code', columns: ['code'])]
class Material
{
    public const KIND_RAW = 'raw';
    public const KIND_FINISHED = 'finished';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    private string $kind;

    #[ORM\Column(type: 'string', length: 20)]
    private string $unit;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata;

    #[ORM\Column(name: 'stock_mutated', type: 'boolean')]
    private bool $stockMutated = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        string $code = '',
        string $name = '',
        string $kind = self::KIND_RAW,
        string $unit = '',
        ?array $metadata = null,
    )
    {
        $this->uuid = UUID::v4();
        $this->code = $code;
        $this->name = $name;
        $this->kind = $kind;
        $this->unit = $unit;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setCode(string $code): self
    {
        if ($this->stockMutated) {
            throw new \LogicException('Material code cannot change after a stock mutation.');
        }

        $this->code = self::required($code, 'code');

        return $this->touch();
    }

    public function setName(string $name): self
    {
        $this->name = self::required($name, 'name');

        return $this->touch();
    }

    public function setKind(string $kind): self
    {
        $this->kind = self::oneOf($kind, [self::KIND_RAW, self::KIND_FINISHED], 'kind');

        return $this->touch();
    }

    public function setUnit(string $unit): self
    {
        $this->unit = self::required($unit, 'unit');

        return $this->touch();
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this->touch();
    }

    public function setStatus(string $status): self
    {
        $this->status = self::oneOf($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], 'status');

        return $this->touch();
    }

    public function markStockMutated(): void
    {
        $this->stockMutated = true;
        $this->touch();
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('Material %s is required.', $field));
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $value, array $allowed, string $field): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid material %s.', $field));
        }

        return $value;
    }
}
