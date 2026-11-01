<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use App\Payment\Entity\Invoice;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_refund')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_refund_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_huifu_refund_no', columns: ['refund_no'])]
#[ORM\Index(name: 'idx_huifu_refund_invoice', columns: ['invoice_id', 'created_at'])]
#[ORM\Index(name: 'idx_huifu_refund_reconcile', columns: ['status', 'next_query_at'])]
class HuifuRefund
{
    public const STATUS_CREATED = 'created';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: HuifuPaymentAttempt::class)]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?HuifuPaymentAttempt $attempt = null;

    #[ORM\Column(name: 'refund_no', type: 'string', length: 64, unique: true)]
    private string $refundNo = '';

    #[ORM\Column(name: 'req_date', type: 'string', length: 8)]
    private string $reqDate = '';

    #[ORM\Column(type: 'bigint')]
    private int $amount = 0;

    #[ORM\Column(name: 'provider_amount', type: 'bigint', options: ['default' => 0])]
    private int $providerAmount = 0;

    #[ORM\Column(name: 'confirmed_amount', type: 'bigint', options: ['default' => 0])]
    private int $confirmedAmount = 0;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => self::STATUS_CREATED])]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column(name: 'provider_refund_id', type: 'string', length: 128, nullable: true)]
    private ?string $providerRefundId = null;

    #[ORM\Column(name: 'response_code', type: 'string', length: 32, nullable: true)]
    private ?string $responseCode = null;

    #[ORM\Column(name: 'response_message', type: 'text', nullable: true)]
    private ?string $responseMessage = null;

    #[ORM\Column(name: 'core_applied', type: 'boolean', options: ['default' => false])]
    private bool $coreApplied = false;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'request_snapshot', type: 'json', nullable: true)]
    private ?array $requestSnapshot = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'response_snapshot', type: 'json', nullable: true)]
    private ?array $responseSnapshot = null;

    #[ORM\Column(name: 'last_queried_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastQueriedAt = null;

    #[ORM\Column(name: 'query_count', type: 'integer', options: ['default' => 0])]
    private int $queryCount = 0;

    #[ORM\Column(name: 'next_query_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextQueryAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        ?Invoice $invoice = null,
        ?HuifuPaymentAttempt $attempt = null,
        string $refundNo = '',
        string $reqDate = '',
        int $amount = 0,
        int $providerAmount = 0,
    ) {
        $this->uuid = UUID::v4();
        $this->invoice = $invoice;
        $this->attempt = $attempt;
        $this->refundNo = $refundNo;
        $this->reqDate = $reqDate;
        $this->amount = $amount;
        $this->providerAmount = $providerAmount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('Huifu refund %s', $this->refundNo); }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function getInvoiceUuid(): ?string { return $this->invoice?->getUuid(); }
    public function getAttempt(): ?HuifuPaymentAttempt { return $this->attempt; }
    public function getAttemptUuid(): ?string { return $this->attempt?->getUuid(); }
    public function getRefundNo(): string { return $this->refundNo; }
    public function getReqDate(): string { return $this->reqDate; }
    public function getAmount(): int { return $this->amount; }
    public function getProviderAmount(): int { return $this->providerAmount; }
    public function getConfirmedAmount(): int { return $this->confirmedAmount; }
    public function getStatus(): string { return $this->status; }
    public function getProviderRefundId(): ?string { return $this->providerRefundId; }
    public function setProviderRefundId(?string $providerRefundId): self { $this->providerRefundId = $providerRefundId; return $this->touch(); }
    public function getResponseCode(): ?string { return $this->responseCode; }
    public function getResponseMessage(): ?string { return $this->responseMessage; }
    public function setProviderResponse(?string $code, ?string $message): self
    {
        $this->responseCode = $code;
        $this->responseMessage = $message;
        return $this->touch();
    }
    public function isCoreApplied(): bool { return $this->coreApplied; }
    public function getCoreApplied(): bool { return $this->coreApplied; }
    public function markCoreApplied(): self { $this->coreApplied = true; return $this->touch(); }
    public function markSubmitted(): self { $this->status = self::STATUS_SUBMITTED; return $this->touch(); }
    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->nextQueryAt = new \DateTimeImmutable('+1 minute');
        return $this->touch();
    }
    public function markUnknown(): self
    {
        $this->status = self::STATUS_UNKNOWN;
        $this->nextQueryAt = new \DateTimeImmutable('+1 minute');
        return $this->touch();
    }
    public function markSucceeded(int $amount): self
    {
        $this->status = self::STATUS_SUCCEEDED;
        $this->confirmedAmount = $amount;
        $this->nextQueryAt = null;
        return $this->touch();
    }
    public function markFailed(?string $message = null): self
    {
        $this->status = self::STATUS_FAILED;
        if ($message !== null) {
            $this->responseMessage = $message;
        }
        $this->nextQueryAt = null;
        return $this->touch();
    }
    public function markCancelled(): self { $this->status = self::STATUS_CANCELLED; $this->nextQueryAt = null; return $this->touch(); }
    /** @return array<string, mixed>|null */
    public function getRequestSnapshot(): ?array { return $this->requestSnapshot; }
    /** @param array<string, mixed> $snapshot */
    public function setRequestSnapshot(array $snapshot): self { $this->requestSnapshot = $snapshot; return $this->touch(); }
    /** @return array<string, mixed>|null */
    public function getResponseSnapshot(): ?array { return $this->responseSnapshot; }
    /** @param array<string, mixed> $snapshot */
    public function setResponseSnapshot(array $snapshot): self { $this->responseSnapshot = $snapshot; return $this->touch(); }
    public function getLastQueriedAt(): ?\DateTimeImmutable { return $this->lastQueriedAt; }
    public function getQueryCount(): int { return $this->queryCount; }
    public function markQueried(): self
    {
        $this->lastQueriedAt = new \DateTimeImmutable();
        $this->queryCount++;
        return $this->touch();
    }
    public function getNextQueryAt(): ?\DateTimeImmutable { return $this->nextQueryAt; }
    public function setNextQueryAt(?\DateTimeImmutable $nextQueryAt): self { $this->nextQueryAt = $nextQueryAt; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
