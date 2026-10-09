<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Core\Utils\UUID;
use App\Settlement\Repository\SettlementPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity(repositoryClass: SettlementPlanRepository::class)]
#[ORM\Table(name: 'settlement_plan')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_plan_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_plan_funding', columns: ['funding_id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_plan_source', columns: ['source_type', 'source_id', 'funding_kind'])]
#[ORM\Index(name: 'idx_settlement_plan_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_settlement_plan_source', columns: ['source_type', 'source_id'])]
#[ORM\Index(name: 'idx_settlement_plan_refund_lock', columns: ['refund_locked_at', 'refund_unlocked_at'])]
#[ORM\HasLifecycleCallbacks]
class SettlementPlan
{
    public const STATUS_PLANNING = 'planning';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_POSTING = 'posting';
    public const STATUS_PARTIALLY_POSTED = 'partially_posted';
    public const STATUS_POSTED = 'posted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSAL_PENDING = 'reversal_pending';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_REVERSAL_FAILED = 'reversal_failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'funding_id', type: 'string', length: 64, unique: true)]
    private string $fundingId;

    #[ORM\Column(name: 'source_type', type: 'string', length: 64)]
    private string $sourceType;

    #[ORM\Column(name: 'source_id', type: 'string', length: 64)]
    private string $sourceId;

    #[ORM\Column(name: 'funding_kind', type: 'string', length: 50, options: ['default' => 'confirmed'])]
    private string $fundingKind = 'confirmed';

    #[ORM\Column(name: 'confirmation_reference', type: 'string', length: 128)]
    private string $confirmationReference;

    #[ORM\Column(name: 'funding_fingerprint', type: 'string', length: 64)]
    private string $fundingFingerprint;

    #[ORM\Column(type: 'string', length: 32)]
    private string $currency;

    #[ORM\Column(name: 'calculation_scale', type: 'smallint')]
    private int $calculationScale;

    #[ORM\Column(name: 'funding_amount_quantum', type: 'string', length: 128)]
    private string $fundingAmountQuantum;

    #[ORM\Column(name: 'allocated_amount_quantum', type: 'string', length: 128)]
    private string $allocatedAmountQuantum;

    #[ORM\Column(name: 'unallocated_amount_quantum', type: 'string', length: 128)]
    private string $unallocatedAmountQuantum;

    #[ORM\Column(name: 'posting_scale', type: 'smallint')]
    private int $postingScale;

    #[ORM\Column(name: 'funding_posting_amount', type: 'string', length: 128)]
    private string $fundingPostingAmount;

    #[ORM\Column(name: 'allocated_posting_amount', type: 'string', length: 128)]
    private string $allocatedPostingAmount;

    #[ORM\Column(name: 'unallocated_posting_amount', type: 'string', length: 128)]
    private string $unallocatedPostingAmount;

    #[ORM\Column(name: 'subject_type', type: 'string', length: 80)]
    private string $subjectType;

    #[ORM\Column(name: 'subject_id', type: 'string', length: 64)]
    private string $subjectId;

    #[ORM\Column(name: 'subject_version', type: 'string', length: 40)]
    private string $subjectVersion;

    /** @var array<mixed> */
    #[ORM\Column(name: 'context_snapshot', type: 'json')]
    private array $contextSnapshot;

    #[ORM\Column(name: 'context_hash', type: 'string', length: 64)]
    private string $contextHash;

    /** @var array<mixed> */
    #[ORM\Column(name: 'funding_snapshot', type: 'json')]
    private array $fundingSnapshot;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'rule_snapshot', type: 'json')]
    private array $ruleSnapshot;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'calculation_trace', type: 'json')]
    private array $calculationTrace;

    #[ORM\Column(name: 'fallback_recipient_type', type: 'string', length: 50, nullable: true)]
    private ?string $fallbackRecipientType = null;

    #[ORM\Column(name: 'fallback_recipient_id', type: 'string', length: 64, nullable: true)]
    private ?string $fallbackRecipientId = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status = self::STATUS_PLANNING;

    #[ORM\Column(name: 'refund_locked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundLockedAt = null;

    #[ORM\Column(name: 'refund_unlocked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundUnlockedAt = null;

    #[ORM\Column(name: 'correlation_id', type: 'string', length: 64, nullable: true)]
    private ?string $correlationId = null;

    #[ORM\Column(name: 'causation_id', type: 'string', length: 64, nullable: true)]
    private ?string $causationId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, SettlementAllocation> */
    #[Ignore]
    #[ORM\OneToMany(targetEntity: SettlementAllocation::class, mappedBy: 'plan', cascade: ['persist'], orphanRemoval: false)]
    private Collection $allocations;

    /**
     * @param array<mixed> $contextSnapshot
     * @param array<mixed> $fundingSnapshot
     * @param array<mixed> $ruleSnapshot
     * @param array<mixed> $calculationTrace
     */
    public function __construct(
        string $fundingId,
        string $sourceType,
        string $sourceId,
        string $fundingKind,
        string $confirmationReference,
        string $fundingFingerprint,
        string $currency,
        int $calculationScale,
        string $fundingAmountQuantum,
        int $postingScale,
        string $fundingPostingAmount,
        string $subjectType,
        string $subjectId,
        string $subjectVersion,
        array $contextSnapshot,
        string $contextHash,
        array $fundingSnapshot,
        array $ruleSnapshot,
        array $calculationTrace,
        ?string $correlationId = null,
        ?string $causationId = null,
    ) {
        $this->uuid = UUID::v4();
        $this->fundingId = $fundingId;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->fundingKind = $fundingKind;
        $this->confirmationReference = $confirmationReference;
        $this->fundingFingerprint = $fundingFingerprint;
        $this->currency = strtoupper($currency);
        $this->calculationScale = $calculationScale;
        $this->fundingAmountQuantum = $fundingAmountQuantum;
        $this->postingScale = $postingScale;
        $this->fundingPostingAmount = $fundingPostingAmount;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->subjectVersion = $subjectVersion;
        $this->contextSnapshot = $contextSnapshot;
        $this->contextHash = $contextHash;
        $this->fundingSnapshot = $fundingSnapshot;
        $this->ruleSnapshot = $ruleSnapshot;
        $this->calculationTrace = $calculationTrace;
        $this->correlationId = $correlationId;
        $this->causationId = $causationId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->allocatedAmountQuantum = '0';
        $this->unallocatedAmountQuantum = $fundingAmountQuantum;
        $this->allocatedPostingAmount = '0';
        $this->unallocatedPostingAmount = $fundingPostingAmount;
        $this->allocations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getFundingId(): string { return $this->fundingId; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): string { return $this->sourceId; }
    public function getFundingKind(): string { return $this->fundingKind; }
    public function getConfirmationReference(): string { return $this->confirmationReference; }
    public function getFundingFingerprint(): string { return $this->fundingFingerprint; }
    public function getCurrency(): string { return $this->currency; }
    public function getCalculationScale(): int { return $this->calculationScale; }
    public function getFundingAmountQuantum(): string { return $this->fundingAmountQuantum; }
    public function getAllocatedAmountQuantum(): string { return $this->allocatedAmountQuantum; }
    public function getUnallocatedAmountQuantum(): string { return $this->unallocatedAmountQuantum; }
    public function getPostingScale(): int { return $this->postingScale; }
    public function getFundingPostingAmount(): string { return $this->fundingPostingAmount; }
    public function getAllocatedPostingAmount(): string { return $this->allocatedPostingAmount; }
    public function getUnallocatedPostingAmount(): string { return $this->unallocatedPostingAmount; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): string { return $this->subjectId; }
    public function getSubjectVersion(): string { return $this->subjectVersion; }
    /** @return array<string, mixed> */
    public function getContextSnapshot(): array { return $this->contextSnapshot; }
    public function getContextHash(): string { return $this->contextHash; }
    /** @return array<string, mixed> */
    public function getFundingSnapshot(): array { return $this->fundingSnapshot; }
    /** @return array<string, mixed> */
    public function getRuleSnapshot(): array { return $this->ruleSnapshot; }
    /** @return array<string, mixed> */
    public function getCalculationTrace(): array { return $this->calculationTrace; }
    public function getFallbackRecipientType(): ?string { return $this->fallbackRecipientType; }
    public function getFallbackRecipientId(): ?string { return $this->fallbackRecipientId; }
    public function getStatus(): string { return $this->status; }
    public function getRefundLockedAt(): ?\DateTimeImmutable { return $this->refundLockedAt; }
    public function getRefundUnlockedAt(): ?\DateTimeImmutable { return $this->refundUnlockedAt; }
    public function getCorrelationId(): ?string { return $this->correlationId; }
    public function getCausationId(): ?string { return $this->causationId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    /** @return Collection<int, SettlementAllocation> */
    public function getAllocations(): Collection { return $this->allocations; }

    public function setFallbackRecipient(?string $type, ?string $id): self
    {
        $this->fallbackRecipientType = $type;
        $this->fallbackRecipientId = $id;
        return $this;
    }

    public function setTotals(
        string $allocatedAmountQuantum,
        string $unallocatedAmountQuantum,
        string $allocatedPostingAmount,
        string $unallocatedPostingAmount,
    ): self {
        $this->allocatedAmountQuantum = $allocatedAmountQuantum;
        $this->unallocatedAmountQuantum = $unallocatedAmountQuantum;
        $this->allocatedPostingAmount = $allocatedPostingAmount;
        $this->unallocatedPostingAmount = $unallocatedPostingAmount;
        $this->touch();
        return $this;
    }

    public function markPlanned(): self
    {
        $this->status = self::STATUS_PLANNED;
        $this->touch();
        return $this;
    }

    public function markPosting(): self
    {
        $this->status = self::STATUS_POSTING;
        $this->touch();
        return $this;
    }

    public function markPartiallyPosted(): self
    {
        $this->status = self::STATUS_PARTIALLY_POSTED;
        $this->lockRefundIfNeeded();
        $this->touch();
        return $this;
    }

    public function markPosted(): self
    {
        $this->status = self::STATUS_POSTED;
        $this->lockRefundIfNeeded();
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
        return $this;
    }

    public function markFailed(): self
    {
        $this->status = self::STATUS_FAILED;
        $this->touch();
        return $this;
    }

    public function markReversalPending(): self
    {
        $this->status = self::STATUS_REVERSAL_PENDING;
        $this->touch();
        return $this;
    }

    public function markReversed(): self
    {
        $this->status = self::STATUS_REVERSED;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
        return $this;
    }

    public function markReversalFailed(): self
    {
        $this->status = self::STATUS_REVERSAL_FAILED;
        $this->touch();
        return $this;
    }

    public function lockRefund(): self
    {
        if ($this->refundLockedAt === null) {
            $this->refundLockedAt = new \DateTimeImmutable();
        }
        $this->touch();
        return $this;
    }

    public function unlockRefund(): self
    {
        if ($this->status !== self::STATUS_REVERSED) {
            throw new \LogicException('Plan must be reversed before refund unlock');
        }
        $this->refundUnlockedAt = new \DateTimeImmutable();
        $this->touch();
        return $this;
    }

    private function lockRefundIfNeeded(): void
    {
        if ($this->refundLockedAt === null) {
            $this->refundLockedAt = new \DateTimeImmutable();
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
