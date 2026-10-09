<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Core\Utils\UUID;
use App\Settlement\Repository\SettlementRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettlementRuleRepository::class)]
#[ORM\Table(name: 'settlement_rule')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_rule_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_rule_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class SettlementRule
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_RETIRED = 'retired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'current_version', type: 'integer', nullable: true)]
    private ?int $currentVersion = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code = '', string $name = '')
    {
        $this->uuid = UUID::v4();
        $this->code = $code;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getStatus(): string { return $this->status; }
    public function getCurrentVersion(): ?int { return $this->currentVersion; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setCode(string $code): self
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('Settlement rule code cannot be empty.');
        }
        $this->code = $code;
        $this->touch();
        return $this;
    }

    public function setName(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Settlement rule name cannot be empty.');
        }
        $this->name = $name;
        $this->touch();
        return $this;
    }
    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_RETIRED], true)) {
            throw new \InvalidArgumentException("Invalid settlement rule status: $status");
        }
        $this->status = $status;
        $this->touch();
        return $this;
    }
    public function setCurrentVersion(?int $version): self { $this->currentVersion = $version; $this->touch(); return $this; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
