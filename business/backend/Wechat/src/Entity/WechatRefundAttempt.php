<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * A persisted WeChat refund request.
 *
 * Only a verified provider success updates refunded accounting; the
 * `accounting_applied_at` marker makes completion idempotent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_refund_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_refund_attempt_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_refund_attempt_refund_no', columns: ['provider_refund_no'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_refund_attempt_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_wechat_refund_attempt_payment', columns: ['payment_attempt_id'])]
class WechatRefundAttempt
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const PENDING_STATUSES = [self::STATUS_REQUESTED, self::STATUS_PROCESSING, self::STATUS_UNKNOWN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: WechatPaymentAttempt::class)]
    #[ORM\JoinColumn(name: 'payment_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?WechatPaymentAttempt $paymentAttempt = null;

    #[ORM\Column(name: 'provider_refund_no', type: 'string', length: 64, unique: true)]
    private string $providerRefundNo = '';

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 64, unique: true)]
    private string $idempotencyKey = '';

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $amount = 0;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_REQUESTED])]
    private string $status = self::STATUS_REQUESTED;

    #[ORM\Column(name: 'provider_refund_id', type: 'string', length: 128, nullable: true)]
    private ?string $providerRefundId = null;

    #[ORM\Column(name: 'accounting_applied_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $accountingAppliedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->providerRefundNo; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getPaymentAttempt(): ?WechatPaymentAttempt { return $this->paymentAttempt; }
    public function setPaymentAttempt(WechatPaymentAttempt $paymentAttempt): self { $this->paymentAttempt = $paymentAttempt; return $this->touch(); }
    public function getProviderRefundNo(): string { return $this->providerRefundNo; }
    public function setProviderRefundNo(string $providerRefundNo): self { $this->providerRefundNo = $providerRefundNo; return $this->touch(); }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function setIdempotencyKey(string $idempotencyKey): self { $this->idempotencyKey = $idempotencyKey; return $this->touch(); }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): self { $this->amount = $amount; return $this->touch(); }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this->touch(); }
    public function isPending(): bool { return in_array($this->status, self::PENDING_STATUSES, true); }
    public function getProviderRefundId(): ?string { return $this->providerRefundId; }
    public function setProviderRefundId(?string $providerRefundId): self { $this->providerRefundId = $providerRefundId; return $this->touch(); }
    public function getAccountingAppliedAt(): ?\DateTimeImmutable { return $this->accountingAppliedAt; }
    public function markAccountingApplied(): self { $this->accountingAppliedAt = new \DateTimeImmutable(); return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
