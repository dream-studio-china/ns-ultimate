<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * A persisted WeChat payment attempt for an Invoice.
 *
 * The attempt pins the trusted Store, the application/merchant revisions, the
 * payer identity, and the expected external amount, so callbacks, queries, and
 * refunds never re-select a merchant from current Store configuration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_payment_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_payment_attempt_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_payment_attempt_order_no', columns: ['provider_order_no'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_payment_attempt_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_wechat_payment_attempt_invoice', columns: ['invoice_uuid'])]
class WechatPaymentAttempt
{
    public const STATUS_CREATED = 'created';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const UNRESOLVED_STATUSES = [self::STATUS_CREATED, self::STATUS_PENDING, self::STATUS_UNKNOWN];

    public const TRADE_JSAPI = 'jsapi';
    public const TRADE_NATIVE = 'native';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'invoice_uuid', type: 'string', length: 36)]
    private string $invoiceUuid = '';

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\ManyToOne(targetEntity: WechatApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'application_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WechatApplicationRevision $applicationRevision = null;

    #[ORM\ManyToOne(targetEntity: WechatMerchantRevision::class)]
    #[ORM\JoinColumn(name: 'merchant_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WechatMerchantRevision $merchantRevision = null;

    #[ORM\Column(name: 'provider_order_no', type: 'string', length: 64, unique: true)]
    private string $providerOrderNo = '';

    #[ORM\Column(name: 'trade_type', type: 'string', length: 20)]
    private string $tradeType = self::TRADE_JSAPI;

    #[ORM\Column(name: 'expected_amount', type: 'bigint', options: ['default' => 0])]
    private int $expectedAmount = 0;

    #[ORM\Column(type: 'string', length: 8, options: ['default' => 'CNY'])]
    private string $currency = 'CNY';

    #[ORM\ManyToOne(targetEntity: WechatIdentity::class)]
    #[ORM\JoinColumn(name: 'payer_identity_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WechatIdentity $payerIdentity = null;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 64, unique: true)]
    private string $idempotencyKey = '';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_CREATED])]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column(name: 'transaction_id', type: 'string', length: 128, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->providerOrderNo; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getInvoiceUuid(): string { return $this->invoiceUuid; }
    public function setInvoiceUuid(string $invoiceUuid): self { $this->invoiceUuid = $invoiceUuid; return $this->touch(); }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this->touch(); }
    public function getApplicationRevision(): ?WechatApplicationRevision { return $this->applicationRevision; }
    public function setApplicationRevision(?WechatApplicationRevision $revision): self { $this->applicationRevision = $revision; return $this->touch(); }
    public function getMerchantRevision(): ?WechatMerchantRevision { return $this->merchantRevision; }
    public function setMerchantRevision(?WechatMerchantRevision $revision): self { $this->merchantRevision = $revision; return $this->touch(); }
    public function getProviderOrderNo(): string { return $this->providerOrderNo; }
    public function setProviderOrderNo(string $providerOrderNo): self { $this->providerOrderNo = $providerOrderNo; return $this->touch(); }
    public function getTradeType(): string { return $this->tradeType; }
    public function setTradeType(string $tradeType): self { $this->tradeType = $tradeType; return $this->touch(); }
    public function getExpectedAmount(): int { return $this->expectedAmount; }
    public function setExpectedAmount(int $expectedAmount): self { $this->expectedAmount = $expectedAmount; return $this->touch(); }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = $currency; return $this->touch(); }
    public function getPayerIdentity(): ?WechatIdentity { return $this->payerIdentity; }
    public function setPayerIdentity(?WechatIdentity $payerIdentity): self { $this->payerIdentity = $payerIdentity; return $this->touch(); }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function setIdempotencyKey(string $idempotencyKey): self { $this->idempotencyKey = $idempotencyKey; return $this->touch(); }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this->touch(); }
    public function isUnresolved(): bool { return in_array($this->status, self::UNRESOLVED_STATUSES, true); }
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function setTransactionId(?string $transactionId): self { $this->transactionId = $transactionId; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
