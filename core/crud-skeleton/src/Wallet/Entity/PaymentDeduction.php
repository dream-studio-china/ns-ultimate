<?php

declare(strict_types=1);

namespace App\Wallet\Entity;

use App\Core\Utils\UUID;
use App\Payment\Entity\Invoice;
use App\Wallet\Repository\PaymentDeductionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentDeductionRepository::class)]
#[ORM\Table(name: 'wallet_payment_deduction')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_payment_deduction_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wallet_payment_deduction_reference', columns: ['reference_id'])]
#[ORM\UniqueConstraint(name: 'uniq_wallet_payment_deduction_invoice_type', columns: ['invoice_id', 'type'])]
#[ORM\Index(name: 'idx_wallet_payment_deduction_invoice_status', columns: ['invoice_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class PaymentDeduction
{
    public const TYPE_WALLET_BALANCE = 'wallet_balance';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_RELEASED = 'released';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'invoice_id', type: 'string', length: 36)]
    private string $invoiceId;

    #[ORM\Column(name: 'invoice_no', type: 'string', length: 64)]
    private string $invoiceNo;

    #[ORM\Column(name: 'payer_id', type: 'integer')]
    private int $payerId;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    #[ORM\Column(name: 'system_wallet_id', type: 'integer')]
    private int $systemWalletId;

    #[ORM\Column(type: 'string', length: 30)]
    private string $type = self::TYPE_WALLET_BALANCE;

    /** Deduction amount in minor units (cents). */
    #[ORM\Column(type: 'bigint')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 10)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'wallet_transaction_id', type: 'string', length: 64, nullable: true)]
    private ?string $walletTransactionId = null;

    #[ORM\Column(name: 'reversal_transaction_id', type: 'string', length: 64, nullable: true)]
    private ?string $reversalTransactionId = null;

    #[ORM\Column(name: 'reference_id', type: 'string', length: 64, unique: true)]
    private string $referenceId;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'applied_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(name: 'released_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(name: 'refunded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    public function __construct(Invoice $invoice, Wallet $wallet, int $systemWalletId, int $amount, string $currency, string $referenceId)
    {
        $payer = $invoice->getPayer();
        if ($payer === null || $payer->getId() === null) {
            throw new \InvalidArgumentException('Invoice payer is required for wallet payment deduction.');
        }

        $this->uuid = UUID::v4();
        $this->invoiceId = $invoice->getUuid();
        $this->invoiceNo = $invoice->getOutTradeNo();
        $this->payerId = $payer->getId();
        $this->wallet = $wallet;
        $this->systemWalletId = $systemWalletId;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->referenceId = $referenceId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getInvoiceId(): string { return $this->invoiceId; }
    public function getInvoiceNo(): string { return $this->invoiceNo; }
    public function getPayerId(): int { return $this->payerId; }
    public function getWallet(): Wallet { return $this->wallet; }
    public function getSystemWalletId(): int { return $this->systemWalletId; }
    public function getType(): string { return $this->type; }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getTransactionId(): ?string { return $this->walletTransactionId; }
    public function getReversalTransactionId(): ?string { return $this->reversalTransactionId; }
    public function getReferenceId(): string { return $this->referenceId; }
    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAppliedAt(): ?\DateTimeImmutable { return $this->appliedAt; }
    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function getRefundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function markApplied(string $walletTransactionId, ?array $metadata = null): self
    {
        $this->status = self::STATUS_APPLIED;
        $this->walletTransactionId = $walletTransactionId;
        $this->appliedAt = new \DateTimeImmutable();
        $this->metadata = $metadata ?: $this->metadata;
        return $this;
    }

    public function markReleased(string $reversalTransactionId, string $reason): self
    {
        $this->status = self::STATUS_RELEASED;
        $this->reversalTransactionId = $reversalTransactionId;
        $this->releasedAt = new \DateTimeImmutable();
        $this->appendMetadata('releaseReason', $reason);
        return $this;
    }

    public function markRefunded(string $reversalTransactionId, string $reason): self
    {
        $this->status = self::STATUS_REFUNDED;
        $this->reversalTransactionId = $reversalTransactionId;
        $this->refundedAt = new \DateTimeImmutable();
        $this->appendMetadata('refundReason', $reason);
        return $this;
    }

    public function markFailed(string $reason): self
    {
        $this->status = self::STATUS_FAILED;
        $this->appendMetadata('failedReason', $reason);
        return $this;
    }

    private function appendMetadata(string $key, mixed $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        $this->metadata = $metadata;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
