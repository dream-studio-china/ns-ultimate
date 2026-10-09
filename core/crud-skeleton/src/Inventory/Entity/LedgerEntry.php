<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\LedgerEntryRepository;
use App\Inventory\Service\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LedgerEntryRepository::class)]
#[ORM\Table(name: 'inventory_ledger_entry')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_ledger_operation', columns: ['type', 'reference_id', 'store_uuid', 'material_id'])]
#[ORM\Index(name: 'idx_inventory_ledger_store_material_created', columns: ['store_uuid', 'material_id', 'created_at'])]
class LedgerEntry
{
    public const TYPE_INITIAL = 'initial';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_RESERVATION = 'reservation';
    public const TYPE_RELEASE = 'release';
    public const TYPE_CONSUME = 'consume';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Material $material;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(name: 'on_hand_delta', type: 'decimal', precision: 20, scale: 6)]
    private string $onHandDelta;

    #[ORM\Column(name: 'reserved_delta', type: 'decimal', precision: 20, scale: 6)]
    private string $reservedDelta;

    #[ORM\Column(name: 'on_hand_after', type: 'decimal', precision: 20, scale: 6)]
    private string $onHandAfter;

    #[ORM\Column(name: 'reserved_after', type: 'decimal', precision: 20, scale: 6)]
    private string $reservedAfter;

    #[ORM\Column(name: 'reference_type', type: 'string', length: 80)]
    private string $referenceType;

    #[ORM\Column(name: 'reference_id', type: 'string', length: 64)]
    private string $referenceId;

    #[ORM\Column(name: 'actor_reference', type: 'string', length: 64, nullable: true)]
    private ?string $actorReference;

    #[ORM\Column(name: 'reason', type: 'text', nullable: true)]
    private ?string $reason;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Stock $stock, string $type, string $onHandDelta, string $reservedDelta, string $referenceType, string $referenceId, ?string $actorReference = null, ?string $reason = null)
    {
        $this->uuid = UUID::v4();
        $this->material = $stock->getMaterial();
        $this->storeUuid = $stock->getStoreUuid();
        $this->type = $type;
        $this->onHandDelta = Quantity::normalize($onHandDelta);
        $this->reservedDelta = Quantity::normalize($reservedDelta);
        $this->onHandAfter = $stock->getOnHandQuantity();
        $this->reservedAfter = $stock->getReservedQuantity();
        $this->referenceType = $referenceType;
        $this->referenceId = $referenceId;
        $this->actorReference = $actorReference;
        $this->reason = $reason;
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
}
