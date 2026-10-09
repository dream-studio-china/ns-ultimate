<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'inventory_reservation')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reservation_id', columns: ['reservation_id'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reservation_store_order', columns: ['store_order_uuid'])]
class Reservation
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONSUMED = 'consumed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'reservation_id', type: 'string', length: 36, unique: true)]
    private string $reservationId;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid;

    #[ORM\Column(name: 'trade_order_uuid', type: 'string', length: 36)]
    private string $tradeOrderUuid;

    #[ORM\Column(name: 'store_order_uuid', type: 'string', length: 36, unique: true)]
    private string $storeOrderUuid;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status = self::STATUS_REQUESTED;

    #[ORM\Column(name: 'request_hash', type: 'string', length: 64)]
    private string $requestHash;

    #[ORM\Column(name: 'rejection_code', type: 'string', length: 50, nullable: true)]
    private ?string $rejectionCode = null;

    #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'released_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    /**
     * @var Collection<int, ReservationLine>
     */
    #[ORM\OneToMany(targetEntity: ReservationLine::class, mappedBy: 'reservation', cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $reservationId, string $storeUuid, string $tradeOrderUuid, string $storeOrderUuid, string $requestHash, ?\DateTimeImmutable $expiresAt)
    {
        $this->uuid = UUID::v4();
        $this->reservationId = $reservationId;
        $this->storeUuid = $storeUuid;
        $this->tradeOrderUuid = $tradeOrderUuid;
        $this->storeOrderUuid = $storeOrderUuid;
        $this->requestHash = $requestHash;
        $this->expiresAt = $expiresAt;
        $this->lines = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getReservationId(): string
    {
        return $this->reservationId;
    }

    public function getStoreUuid(): string
    {
        return $this->storeUuid;
    }

    public function getTradeOrderUuid(): string
    {
        return $this->tradeOrderUuid;
    }

    public function getStoreOrderUuid(): string
    {
        return $this->storeOrderUuid;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    public function getRejectionCode(): ?string
    {
        return $this->rejectionCode;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    /**
     * @return list<ReservationLine>
     */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function addLine(ReservationLine $line): void
    {
        $line->setReservation($this);
        $this->lines->add($line);
    }

    public function confirm(): void
    {
        $this->status = self::STATUS_CONFIRMED;
        $this->touch();
    }

    public function reject(string $code, string $reason): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->rejectionCode = $code;
        $this->rejectionReason = $reason;
        $this->touch();
    }

    public function release(): bool
    {
        if ($this->status !== self::STATUS_CONFIRMED) {
            return false;
        }

        $this->status = self::STATUS_RELEASED;
        $this->releasedAt = new \DateTimeImmutable();
        $this->touch();

        return true;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
