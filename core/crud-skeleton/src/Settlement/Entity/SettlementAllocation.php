<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Core\Utils\UUID;
use App\Settlement\Repository\SettlementAllocationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettlementAllocationRepository::class)]
#[ORM\Table(name: 'settlement_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_allocation_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_allocation_plan_key', columns: ['plan_uuid', 'allocation_key'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_allocation_posting_key', columns: ['posting_idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_allocation_reversal_key', columns: ['reversal_idempotency_key'])]
#[ORM\Index(name: 'idx_settlement_allocation_plan_status', columns: ['plan_uuid', 'status'])]
#[ORM\Index(name: 'idx_settlement_allocation_retry', columns: ['status', 'next_attempt_at'])]
#[ORM\Index(name: 'idx_settlement_allocation_source_item', columns: ['source_item_id'])]
#[ORM\HasLifecycleCallbacks]
class SettlementAllocation
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_POSTING_REQUESTED = 'posting_requested';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RETRYABLE_FAILURE = 'retryable_failure';
    public const STATUS_REVERSAL_REQUESTED = 'reversal_requested';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_REVERSAL_PENDING = 'reversal_pending';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: SettlementPlan::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'plan_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SettlementPlan $plan;

    #[ORM\Column(name: 'plan_uuid', type: 'string', length: 36)]
    private string $planUuid;

    #[ORM\Column(type: 'integer')]
    private int $sequence;

    #[ORM\Column(name: 'allocation_key', type: 'string', length: 128)]
    private string $allocationKey;

    #[ORM\Column(name: 'recipient_type', type: 'string', length: 50)]
    private string $recipientType;

    #[ORM\Column(name: 'recipient_id', type: 'string', length: 64)]
    private string $recipientId;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'recipient_snapshot', type: 'json')]
    private array $recipientSnapshot;

    #[ORM\Column(name: 'rule_code', type: 'string', length: 100, nullable: true)]
    private ?string $ruleCode = null;

    #[ORM\Column(name: 'rule_version_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $ruleVersionUuid = null;

    #[ORM\Column(name: 'source_item_id', type: 'string', length: 64, nullable: true)]
    private ?string $sourceItemId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'source_item_snapshot', type: 'json', nullable: true)]
    private ?array $sourceItemSnapshot = null;

    #[ORM\Column(name: 'reason_code', type: 'string', length: 100)]
    private string $reasonCode;

    #[ORM\Column(name: 'exact_amount_quantum', type: 'string', length: 128)]
    private string $exactAmountQuantum;

    #[ORM\Column(name: 'posting_amount', type: 'string', length: 128)]
    private string $postingAmount;

    #[ORM\Column(name: 'posting_scale', type: 'smallint')]
    private int $postingScale;

    #[ORM\Column(name: 'rounding_delta_quantum', type: 'string', length: 128)]
    private string $roundingDeltaQuantum;

    #[ORM\Column(name: 'rounding_rank', type: 'integer', nullable: true)]
    private ?int $roundingRank = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status;

    #[ORM\Column(name: 'posting_reference', type: 'string', length: 128, nullable: true)]
    private ?string $postingReference = null;

    #[ORM\Column(name: 'posting_idempotency_key', type: 'string', length: 128, unique: true)]
    private string $postingIdempotencyKey;

    #[ORM\Column(name: 'posted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    #[ORM\Column(name: 'reversal_reference', type: 'string', length: 128, nullable: true)]
    private ?string $reversalReference = null;

    #[ORM\Column(name: 'reversal_idempotency_key', type: 'string', length: 128, unique: true, nullable: true)]
    private ?string $reversalIdempotencyKey = null;

    #[ORM\Column(name: 'reversed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reversedAt = null;

    #[ORM\Column(name: 'failure_code', type: 'string', length: 100, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(name: 'failure_detail', type: 'text', nullable: true)]
    private ?string $failureDetail = null;

    #[ORM\Column(name: 'attempt_count', type: 'integer', options: ['default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(name: 'next_attempt_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $recipientSnapshot
     * @param array<string, mixed>|null $sourceItemSnapshot
     */
    public function __construct(
        SettlementPlan $plan,
        int $sequence,
        string $allocationKey,
        string $recipientType,
        string $recipientId,
        array $recipientSnapshot,
        ?string $ruleCode,
        ?string $ruleVersionUuid,
        string $reasonCode,
        string $exactAmountQuantum,
        string $postingAmount,
        int $postingScale,
        string $roundingDeltaQuantum,
        string $postingIdempotencyKey,
        ?string $sourceItemId = null,
        ?array $sourceItemSnapshot = null,
    ) {
        $this->uuid = UUID::v4();
        $this->plan = $plan;
        $this->planUuid = $plan->getUuid();
        $this->sequence = $sequence;
        $this->allocationKey = $allocationKey;
        $this->recipientType = $recipientType;
        $this->recipientId = $recipientId;
        $this->recipientSnapshot = $recipientSnapshot;
        $this->ruleCode = $ruleCode;
        $this->ruleVersionUuid = $ruleVersionUuid;
        $this->sourceItemId = $sourceItemId;
        $this->sourceItemSnapshot = $sourceItemSnapshot;
        $this->reasonCode = $reasonCode;
        $this->exactAmountQuantum = $exactAmountQuantum;
        $this->postingAmount = $postingAmount;
        $this->postingScale = $postingScale;
        $this->roundingDeltaQuantum = $roundingDeltaQuantum;
        $this->postingIdempotencyKey = $postingIdempotencyKey;
        $this->status = self::STATUS_PLANNED;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getPlan(): SettlementPlan { return $this->plan; }
    public function getPlanUuid(): string { return $this->planUuid; }
    public function getSequence(): int { return $this->sequence; }
    public function getAllocationKey(): string { return $this->allocationKey; }
    public function getRecipientType(): string { return $this->recipientType; }
    public function getRecipientId(): string { return $this->recipientId; }
    /** @return array<string, mixed> */
    public function getRecipientSnapshot(): array { return $this->recipientSnapshot; }
    public function getRuleCode(): ?string { return $this->ruleCode; }
    public function getRuleVersionUuid(): ?string { return $this->ruleVersionUuid; }
    public function getSourceItemId(): ?string { return $this->sourceItemId; }
    /** @return array<string, mixed>|null */
    public function getSourceItemSnapshot(): ?array { return $this->sourceItemSnapshot; }
    public function getReasonCode(): string { return $this->reasonCode; }
    public function getExactAmountQuantum(): string { return $this->exactAmountQuantum; }
    public function getPostingAmount(): string { return $this->postingAmount; }
    public function getPostingScale(): int { return $this->postingScale; }
    public function getRoundingDeltaQuantum(): string { return $this->roundingDeltaQuantum; }
    public function getRoundingRank(): ?int { return $this->roundingRank; }
    public function getStatus(): string { return $this->status; }
    public function getPostingReference(): ?string { return $this->postingReference; }
    public function getPostingIdempotencyKey(): string { return $this->postingIdempotencyKey; }
    public function getPostedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    public function getReversalReference(): ?string { return $this->reversalReference; }
    public function getReversalIdempotencyKey(): ?string { return $this->reversalIdempotencyKey; }
    public function getReversedAt(): ?\DateTimeImmutable { return $this->reversedAt; }
    public function getFailureCode(): ?string { return $this->failureCode; }
    public function getFailureDetail(): ?string { return $this->failureDetail; }
    public function getAttemptCount(): int { return $this->attemptCount; }
    public function getNextAttemptAt(): ?\DateTimeImmutable { return $this->nextAttemptAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setRoundingRank(?int $rank): self { $this->roundingRank = $rank; return $this; }

    public function markPostingRequested(): self
    {
        $this->assertStatus(self::STATUS_PLANNED, self::STATUS_RETRYABLE_FAILURE);
        $this->status = self::STATUS_POSTING_REQUESTED;
        return $this;
    }

    public function markPosted(string $postingReference): self
    {
        $this->assertStatus(self::STATUS_POSTING_REQUESTED, self::STATUS_RETRYABLE_FAILURE);
        if ($this->postingReference !== null && $this->postingReference !== $postingReference) {
            throw new \LogicException('Cannot replace an existing posting reference');
        }
        $this->postingReference = $postingReference;
        $this->postedAt = new \DateTimeImmutable();
        $this->failureCode = null;
        $this->failureDetail = null;
        $this->status = self::STATUS_POSTED;
        return $this;
    }

    public function markRetryableFailure(string $code, string $detail, \DateTimeImmutable $nextAttemptAt): self
    {
        $this->status = self::STATUS_RETRYABLE_FAILURE;
        $this->failureCode = $code;
        $this->failureDetail = $detail;
        $this->attemptCount++;
        $this->nextAttemptAt = $nextAttemptAt;
        return $this;
    }

    public function markFailed(string $code, string $detail): self
    {
        $this->status = self::STATUS_FAILED;
        $this->failureCode = $code;
        $this->failureDetail = $detail;
        return $this;
    }

    public function cancel(): self
    {
        $this->assertStatus(self::STATUS_PLANNED, self::STATUS_POSTING_REQUESTED, self::STATUS_RETRYABLE_FAILURE);
        $this->status = self::STATUS_CANCELLED;
        return $this;
    }

    public function markReversalRequested(): self
    {
        $this->assertStatus(self::STATUS_POSTED);
        $this->status = self::STATUS_REVERSAL_REQUESTED;
        return $this;
    }

    public function markReversed(string $reversalIdempotencyKey, string $reversalReference): self
    {
        $this->assertStatus(self::STATUS_REVERSAL_REQUESTED, self::STATUS_REVERSAL_PENDING);
        $this->reversalIdempotencyKey = $reversalIdempotencyKey;
        $this->reversalReference = $reversalReference;
        $this->reversedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_REVERSED;
        return $this;
    }

    public function markReversalPending(string $detail): self
    {
        $this->status = self::STATUS_REVERSAL_PENDING;
        $this->failureCode = 'reversal_insufficient_funds';
        $this->failureDetail = $detail;
        return $this;
    }

    private function assertStatus(string ...$allowed): void
    {
        if (!in_array($this->status, $allowed, true)) {
            throw new \LogicException(sprintf('SettlementAllocation cannot transition from status "%s"', $this->status));
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
