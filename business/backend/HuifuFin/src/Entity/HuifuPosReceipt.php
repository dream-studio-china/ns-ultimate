<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use App\Payment\Entity\Invoice;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_pos_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_pos_receipt_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_huifu_pos_receipt_transaction', columns: ['provider_transaction_id'])]
#[ORM\Index(name: 'idx_huifu_pos_receipt_store', columns: ['store_uuid', 'status'])]
class HuifuPosReceipt
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_LINKED = 'linked';
    public const STATUS_UNCLAIMED = 'unclaimed';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'provider_transaction_id', type: 'string', length: 128, unique: true)]
    private string $providerTransactionId = '';

    #[ORM\Column(name: 'merchant_uuid', type: 'string', length: 36)]
    private string $merchantUuid = '';

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(name: 'terminal_id', type: 'string', length: 64)]
    private string $terminalId = '';

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\Column(name: 'order_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $orderUuid = null;

    #[ORM\Column(type: 'bigint')]
    private int $amount = 0;

    #[ORM\Column(type: 'string', length: 8, options: ['default' => 'CNY'])]
    private string $currency = 'CNY';

    #[ORM\Column(name: 'paid_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => self::STATUS_RECEIVED])]
    private string $status = self::STATUS_RECEIVED;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'raw_data', type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $providerTransactionId = '',
        string $merchantUuid = '',
        string $storeUuid = '',
        string $terminalId = '',
        int $amount = 0,
        string $currency = 'CNY',
        ?\DateTimeImmutable $paidAt = null,
    ) {
        $this->uuid = UUID::v4();
        $this->providerTransactionId = $providerTransactionId;
        $this->merchantUuid = $merchantUuid;
        $this->storeUuid = $storeUuid;
        $this->terminalId = $terminalId;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->paidAt = $paidAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('Huifu POS receipt %s', $this->providerTransactionId); }
    public function getProviderTransactionId(): string { return $this->providerTransactionId; }
    public function getMerchantUuid(): string { return $this->merchantUuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getTerminalId(): string { return $this->terminalId; }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function getInvoiceUuid(): ?string { return $this->invoice?->getUuid(); }
    public function setInvoice(?Invoice $invoice): self { $this->invoice = $invoice; return $this->touch(); }
    public function getOrderUuid(): ?string { return $this->orderUuid; }
    public function setOrderUuid(?string $orderUuid): self { $this->orderUuid = $orderUuid; return $this->touch(); }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function getStatus(): string { return $this->status; }
    public function markLinked(): self { $this->status = self::STATUS_LINKED; $this->error = null; return $this->touch(); }
    public function markUnclaimed(?string $reason = null): self
    {
        $this->status = self::STATUS_UNCLAIMED;
        $this->error = $reason;
        return $this->touch();
    }
    public function markRejected(string $reason): self { $this->status = self::STATUS_REJECTED; $this->error = $reason; return $this->touch(); }
    /** Keep the receipt retryable after a transient failure while applying the invoice. */
    public function markProcessingError(string $reason): self { $this->error = $reason; return $this->touch(); }
    /** @return array<string, mixed>|null */
    public function getRawData(): ?array { return $this->rawData; }
    /** @param array<string, mixed> $rawData */
    public function setRawData(array $rawData): self { $this->rawData = $rawData; return $this->touch(); }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
