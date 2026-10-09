<?php

declare(strict_types=1);

namespace App\Promotion\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Promotion\Repository\PromotionTemplateRepository::class)]
#[ORM\Table(name: 'promotion_template')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_promotion_template_uuid', columns: ['uuid'])]
class PromotionTemplate
{
    public const PHASE_ALL = -1;
    public const PHASE_INNER = 0;
    public const PHASE_OUTER = 1;

    public const TYPE_FULL_REDUCTION = 'full_reduction';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_GIFT = 'gift';
    public const TYPE_NTH_DISCOUNT = 'nth_discount';
    public const TYPE_TIERED = 'tiered';
    public const TYPE_FREE_SHIPPING = 'free_shipping';
    public const TYPE_MEMBER_DISCOUNT = 'member_discount';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type = self::TYPE_FULL_REDUCTION;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $phase = self::PHASE_INNER;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(type: 'text')]
    private string $dsl = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fields = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $astCache = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->touch();
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        $this->touch();
        return $this;
    }

    public function getPhase(): int
    {
        return $this->phase;
    }

    public function setPhase(int $phase): self
    {
        $this->phase = $phase;
        $this->touch();
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->touch();
        return $this;
    }

    public function getDsl(): string
    {
        return $this->dsl;
    }

    public function setDsl(string $dsl): self
    {
        $this->dsl = $dsl;
        $this->touch();
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getFields(): ?array
    {
        return $this->fields;
    }

    /** @param array<string, mixed>|null $fields */
    public function setFields(?array $fields): self
    {
        $this->fields = $fields;
        $this->touch();
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAstCache(): ?array
    {
        return $this->astCache;
    }

    /** @param array<string, mixed>|null $astCache */
    public function setAstCache(?array $astCache): self
    {
        $this->astCache = $astCache;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
