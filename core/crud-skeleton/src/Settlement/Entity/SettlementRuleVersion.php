<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Core\Utils\UUID;
use App\Settlement\Repository\SettlementRuleVersionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettlementRuleVersionRepository::class)]
#[ORM\Table(name: 'settlement_rule_version')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_rule_version_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_rule_version_rule_version', columns: ['rule_uuid', 'version'])]
#[ORM\Index(name: 'idx_settlement_rule_version_active', columns: ['status', 'effective_from', 'effective_to'])]
#[ORM\HasLifecycleCallbacks]
class SettlementRuleVersion
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

    #[ORM\Column(name: 'rule_uuid', type: 'string', length: 36)]
    private string $ruleUuid;

    #[ORM\Column(type: 'integer')]
    private int $version;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $definition;

    #[ORM\Column(name: 'definition_hash', type: 'string', length: 64)]
    private string $definitionHash;

    #[ORM\Column(name: 'effective_from', type: 'datetime_immutable')]
    private \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(name: 'effective_to', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $effectiveTo;

    #[ORM\Column(type: 'integer')]
    private int $priority;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'published_by', type: 'string', length: 64, nullable: true)]
    private ?string $publishedBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        string $ruleUuid,
        int $version,
        array $definition,
        string $definitionHash,
        \DateTimeImmutable $effectiveFrom,
        int $priority,
        ?\DateTimeImmutable $effectiveTo = null,
    ) {
        $this->uuid = UUID::v4();
        $this->ruleUuid = $ruleUuid;
        $this->version = $version;
        $this->definition = $definition;
        $this->definitionHash = $definitionHash;
        $this->effectiveFrom = $effectiveFrom;
        $this->effectiveTo = $effectiveTo;
        $this->priority = $priority;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getRuleUuid(): string { return $this->ruleUuid; }
    public function getVersion(): int { return $this->version; }
    /** @return array<string, mixed> */
    public function getDefinition(): array { return $this->definition; }
    public function getDefinitionHash(): string { return $this->definitionHash; }
    public function getEffectiveFrom(): \DateTimeImmutable { return $this->effectiveFrom; }
    public function getEffectiveTo(): ?\DateTimeImmutable { return $this->effectiveTo; }
    public function getPriority(): int { return $this->priority; }
    public function getStatus(): string { return $this->status; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getPublishedBy(): ?string { return $this->publishedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_RETIRED], true)) {
            throw new \InvalidArgumentException("Invalid settlement rule version status: $status");
        }
        $this->status = $status;
        return $this;
    }

    /** @param array<string, mixed> $definition */
    public function configure(
        array $definition,
        string $definitionHash,
        int $priority,
        \DateTimeImmutable $effectiveFrom,
        ?\DateTimeImmutable $effectiveTo,
    ): self {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \LogicException('Only draft rule versions can be configured.');
        }
        $this->definition = $definition;
        $this->definitionHash = $definitionHash;
        $this->priority = $priority;
        $this->effectiveFrom = $effectiveFrom;
        $this->effectiveTo = $effectiveTo;
        return $this;
    }

    public function publish(string $publishedBy): self
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \LogicException('Only draft rule versions can be published.');
        }
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = new \DateTimeImmutable();
        $this->publishedBy = $publishedBy;
        return $this;
    }

    public function retire(): self
    {
        $this->status = self::STATUS_RETIRED;
        return $this;
    }
}
