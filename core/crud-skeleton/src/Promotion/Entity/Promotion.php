<?php

declare(strict_types=1);

namespace App\Promotion\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Promotion\Repository\PromotionRepository::class)]
#[ORM\Table(name: 'promotion')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_promotion_uuid', columns: ['uuid'])]
class Promotion
{
    public const CONFLICT_STACKABLE = 'stackable';
    public const CONFLICT_EXCLUSIVE = 'exclusive';
    public const CONFLICT_LOCK_ITEM = 'lock_item';
    public const CONFLICT_BEST_PRICE = 'best_price';

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

    #[ORM\ManyToOne(targetEntity: PromotionTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PromotionTemplate $template = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $storeCode = '';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $config = null;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'stackable'])]
    private string $conflictMode = self::CONFLICT_STACKABLE;

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

    public function getTemplate(): ?PromotionTemplate
    {
        return $this->template;
    }

    public function setTemplate(?PromotionTemplate $template): self
    {
        $this->template = $template;
        $this->touch();
        return $this;
    }

    public function getStoreCode(): string
    {
        return $this->storeCode;
    }

    public function setStoreCode(string $storeCode): self
    {
        $this->storeCode = $storeCode;
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

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;
        $this->touch();
        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;
        $this->touch();
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getConfig(): ?array
    {
        return $this->config;
    }

    /** @param array<string, mixed>|null $config */
    public function setConfig(?array $config): self
    {
        $this->config = $config;
        $this->touch();
        return $this;
    }

    public function getConflictMode(): string
    {
        return $this->conflictMode;
    }

    public function setConflictMode(string $conflictMode): self
    {
        $this->conflictMode = $conflictMode;
        $this->touch();
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

