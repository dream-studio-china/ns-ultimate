<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use App\Payment\Entity\Invoice;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_payment_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_payment_attempt_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_huifu_payment_attempt_req_seq', columns: ['req_seq_id'])]
#[ORM\Index(name: 'idx_huifu_payment_attempt_invoice', columns: ['invoice_id', 'created_at'])]
#[ORM\Index(name: 'idx_huifu_payment_attempt_store', columns: ['store_uuid', 'status'])]
#[ORM\Index(name: 'idx_huifu_payment_attempt_reconcile', columns: ['status', 'next_query_at'])]
class HuifuPaymentAttempt
{
    public const STATUS_CREATED = 'created';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(name: 'provider_profile_uuid', type: 'string', length: 36)]
    private string $providerProfileUuid = '';

    #[ORM\Column(name: 'merchant_uuid', type: 'string', length: 36)]
    private string $merchantUuid = '';

    #[ORM\Column(name: 'huifu_id', type: 'string', length: 64)]
    private string $huifuId = '';

    #[ORM\Column(name: 'credential_version', type: 'integer')]
    private int $credentialVersion = 1;

    #[ORM\Column(name: 'req_date', type: 'string', length: 8)]
    private string $reqDate = '';

    #[ORM\Column(name: 'req_seq_id', type: 'string', length: 64, unique: true)]
    private string $reqSeqId = '';

    #[ORM\Column(type: 'string', length: 32)]
    private string $channel = 'jspay';

    #[ORM\Column(name: 'trade_type', type: 'string', length: 32)]
    private string $tradeType = 'T_JSAPI';

    #[ORM\Column(type: 'bigint')]
    private int $amount = 0;

    #[ORM\Column(type: 'string', length: 8, options: ['default' => 'CNY'])]
    private string $currency = 'CNY';

    #[ORM\Column(type: 'string', length: 30, options: ['default' => self::STATUS_CREATED])]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column(name: 'response_code', type: 'string', length: 32, nullable: true)]
    private ?string $responseCode = null;

    #[ORM\Column(name: 'response_message', type: 'text', nullable: true)]
    private ?string $responseMessage = null;

    #[ORM\Column(name: 'hf_seq_id', type: 'string', length: 128, nullable: true)]
    private ?string $hfSeqId = null;

    #[ORM\Column(name: 'transaction_id', type: 'string', length: 128, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(name: 'provider_trans_status', type: 'string', length: 16, nullable: true)]
    private ?string $providerTransStatus = null;

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
        string $storeUuid = '',
        string $providerProfileUuid = '',
        string $merchantUuid = '',
        string $huifuId = '',
        int $credentialVersion = 1,
        string $reqDate = '',
        string $reqSeqId = '',
        string $channel = 'jspay',
        string $tradeType = 'T_JSAPI',
        int $amount = 0,
        string $currency = 'CNY',
    ) {
        $this->uuid = UUID::v4();
        $this->invoice = $invoice;
        $this->storeUuid = $storeUuid;
        $this->providerProfileUuid = $providerProfileUuid;
        $this->merchantUuid = $merchantUuid;
        $this->huifuId = $huifuId;
        $this->credentialVersion = $credentialVersion;
        $this->reqDate = $reqDate;
        $this->reqSeqId = $reqSeqId;
        $this->channel = $channel;
        $this->tradeType = $tradeType;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('Huifu payment attempt %s', $this->reqSeqId); }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function getInvoiceUuid(): ?string { return $this->invoice?->getUuid(); }
    public function setInvoice(Invoice $invoice): self { $this->invoice = $invoice; return $this->touch(); }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getProviderProfileUuid(): string { return $this->providerProfileUuid; }
    public function getMerchantUuid(): string { return $this->merchantUuid; }
    public function getHuifuId(): string { return $this->huifuId; }
    public function getCredentialVersion(): int { return $this->credentialVersion; }
    public function getReqDate(): string { return $this->reqDate; }
    public function getReqSeqId(): string { return $this->reqSeqId; }
    public function getChannel(): string { return $this->channel; }
    public function getTradeType(): string { return $this->tradeType; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): self { $this->amount = $amount; return $this->touch(); }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function markSubmitted(): self { $this->status = self::STATUS_SUBMITTED; return $this->touch(); }
    public function markPending(): self
    {
        $this->status = self::STATUS_PENDING;
        $this->nextQueryAt = new \DateTimeImmutable('+1 minute');
        return $this->touch();
    }
    public function markUnknown(): self
    {
        $this->status = self::STATUS_UNKNOWN;
        $this->nextQueryAt = new \DateTimeImmutable('+1 minute');
        return $this->touch();
    }
    public function markSucceeded(?string $transactionId = null, ?\DateTimeImmutable $paidAt = null): self
    {
        $this->status = self::STATUS_SUCCEEDED;
        $this->transactionId = $transactionId ?? $this->transactionId;
        $this->providerTransStatus = 'S';
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
    public function markClosed(): self { $this->status = self::STATUS_CLOSED; $this->nextQueryAt = null; return $this->touch(); }
    public function getResponseCode(): ?string { return $this->responseCode; }
    public function getResponseMessage(): ?string { return $this->responseMessage; }
    public function setProviderResponse(?string $code, ?string $message): self
    {
        $this->responseCode = $code;
        $this->responseMessage = $message;
        return $this->touch();
    }
    public function getHfSeqId(): ?string { return $this->hfSeqId; }
    public function setHfSeqId(?string $hfSeqId): self { $this->hfSeqId = $hfSeqId; return $this->touch(); }
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function setTransactionId(?string $transactionId): self { $this->transactionId = $transactionId; return $this->touch(); }
    public function getProviderTransStatus(): ?string { return $this->providerTransStatus; }
    public function setProviderTransStatus(?string $status): self { $this->providerTransStatus = $status; return $this->touch(); }
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
    public function markQueried(?string $result = null): self
    {
        $this->lastQueriedAt = new \DateTimeImmutable();
        $this->queryCount++;
        if ($result === null) {
            $this->nextQueryAt = new \DateTimeImmutable('+5 minutes');
        }
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
