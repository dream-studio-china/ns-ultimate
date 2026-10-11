<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'business_counter')]
#[ORM\UniqueConstraint(name: 'uniq_business_counter_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_business_counter_store_code', columns: ['store_uuid', 'code'])]
#[ORM\Index(name: 'idx_business_counter_store_enabled', columns: ['store_uuid', 'enabled'])]
class Counter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(type: 'string', length: 50)]
    private string $code = '';

    #[ORM\Column(type: 'string', length: 120)]
    private string $name = '';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacity = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this->touch(); }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this->touch(); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this->touch(); }
    public function getCapacity(): ?int { return $this->capacity; }
    public function setCapacity(?int $capacity): self { $this->capacity = $capacity; return $this->touch(); }
    public function getEnabled(): bool { return $this->enabled; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
