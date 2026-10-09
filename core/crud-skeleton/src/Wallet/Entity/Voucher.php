<?php

declare(strict_types=1);

namespace App\Wallet\Entity;

use App\Core\Utils\UUID;
use App\Wallet\Repository\VoucherRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity(repositoryClass: VoucherRepository::class)]
#[ORM\Table(name: 'wallet_voucher')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_voucher_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wallet_voucher_reference', columns: ['reference_id'])]
#[ORM\UniqueConstraint(name: 'uniq_wallet_voucher_source', columns: ['voucher_type', 'voucher_id'])]
#[ORM\Index(name: 'idx_wallet_voucher_fund_status', columns: ['fund_source', 'status'])]
#[ORM\Index(name: 'idx_wallet_voucher_currency_status', columns: ['currency', 'status'])]
#[ORM\Index(name: 'idx_wallet_voucher_wallet', columns: ['wallet_id'])]
#[ORM\HasLifecycleCallbacks]
class Voucher
{
    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    public const FUND_SOURCE_EXTERNAL = 'external';
    public const FUND_SOURCE_INTERNAL = 'internal';

    public const VOUCHER_TYPE_MANUAL = 'manual';
    public const VOUCHER_TYPE_INVOICE = 'invoice';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 10)]
    private string $direction;

    #[ORM\Column(name: 'fund_source', type: 'string', length: 10)]
    private string $fundSource;

    #[ORM\Column(name: 'voucher_type', type: 'string', length: 50)]
    private string $voucherType;

    #[ORM\Column(name: 'voucher_id', type: 'string', length: 64)]
    private string $voucherId;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    /** Credit/debit amount in minor units (cents). */
    #[ORM\Column(type: 'bigint')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 32)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'wallet_transaction_id', type: 'string', length: 64, nullable: true)]
    private ?string $walletTransactionId = null;

    #[ORM\Column(name: 'reversal_transaction_id', type: 'string', length: 64, nullable: true)]
    private ?string $reversalTransactionId = null;

    #[ORM\Column(name: 'reference_id', type: 'string', length: 64, unique: true)]
    private string $referenceId;

    #[ORM\Column(name: 'created_by', type: 'string', length: 64)]
    private string $createdBy;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'applied_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(name: 'reversed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reversedAt = null;

    /** @var Collection<int, VoucherComment> */
    #[Ignore]
    #[ORM\OneToMany(targetEntity: VoucherComment::class, mappedBy: 'voucher', cascade: ['persist'])]
    private Collection $comments;

    public function __construct(
        Wallet $wallet,
        string $direction,
        string $fundSource,
        string $voucherType,
        string $voucherId,
        int $amount,
        string $currency,
        string $referenceId,
        string $createdBy,
        ?string $reason = null
    ) {
        $this->uuid = UUID::v4();
        $this->wallet = $wallet;
        $this->setDirection($direction);
        $this->setFundSource($fundSource);
        $this->voucherType = $voucherType;
        $this->voucherId = $voucherId;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->referenceId = $referenceId;
        $this->createdBy = $createdBy;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getDirection(): string { return $this->direction; }
    public function getFundSource(): string { return $this->fundSource; }
    public function getVoucherType(): string { return $this->voucherType; }
    public function getVoucherId(): string { return $this->voucherId; }
    public function getWallet(): Wallet { return $this->wallet; }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getTransactionId(): ?string { return $this->walletTransactionId; }
    public function getReversalTransactionId(): ?string { return $this->reversalTransactionId; }
    public function getReferenceId(): string { return $this->referenceId; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getReason(): ?string { return $this->reason; }
    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAppliedAt(): ?\DateTimeImmutable { return $this->appliedAt; }
    public function getReversedAt(): ?\DateTimeImmutable { return $this->reversedAt; }

    private function setDirection(string $direction): void
    {
        if (!in_array($direction, [self::DIRECTION_CREDIT, self::DIRECTION_DEBIT], true)) {
            throw new \InvalidArgumentException("Invalid voucher direction: $direction");
        }
        $this->direction = $direction;
    }

    private function setFundSource(string $fundSource): void
    {
        if (!in_array($fundSource, [self::FUND_SOURCE_EXTERNAL, self::FUND_SOURCE_INTERNAL], true)) {
            throw new \InvalidArgumentException("Invalid fund source: $fundSource");
        }
        $this->fundSource = $fundSource;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function markApplied(string $walletTransactionId, ?array $metadata = null): self
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \LogicException(sprintf('Voucher cannot be applied from status "%s".', $this->status));
        }
        $this->status = self::STATUS_APPLIED;
        $this->walletTransactionId = $walletTransactionId;
        $this->appliedAt = new \DateTimeImmutable();
        $this->metadata = $metadata ?: $this->metadata;
        return $this;
    }

    public function markReversed(string $reversalTransactionId, string $reason): self
    {
        if ($this->status !== self::STATUS_APPLIED) {
            throw new \LogicException(sprintf('Voucher cannot be reversed from status "%s".', $this->status));
        }
        $this->status = self::STATUS_REVERSED;
        $this->reversalTransactionId = $reversalTransactionId;
        $this->reversedAt = new \DateTimeImmutable();
        // Preserve the creation reason; record the reversal reason separately.
        $metadata = $this->metadata ?? [];
        $metadata['reversalReason'] = $reason;
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Append an immutable annotation. Notes cannot be edited or removed.
     */
    public function addComment(string $actor, string $text): self
    {
        $this->comments->add(new VoucherComment($this, $actor, $text));
        return $this;
    }

    /**
     * @return Collection<int, VoucherComment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function markFailed(string $reason): self
    {
        $this->status = self::STATUS_FAILED;
        $this->reason = $reason;
        return $this;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
