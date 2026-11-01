<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_pos_device')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_pos_device_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_huifu_pos_device_terminal', columns: ['merchant_id', 'terminal_id'])]
class HuifuPosDevice
{
    public const MODE_LINKED_ORDER = 'linked_order';
    public const MODE_UNCLAIMED = 'unclaimed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: HuifuMerchant::class)]
    #[ORM\JoinColumn(name: 'merchant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?HuifuMerchant $merchant = null;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(name: 'terminal_id', type: 'string', length: 64)]
    private string $terminalId = '';

    #[ORM\Column(type: 'string', length: 32, options: ['default' => self::MODE_UNCLAIMED])]
    private string $mode = self::MODE_UNCLAIMED;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        ?HuifuMerchant $merchant = null,
        string $storeUuid = '',
        string $terminalId = '',
    ) {
        $this->uuid = UUID::v4();
        $this->merchant = $merchant;
        $this->storeUuid = $storeUuid;
        $this->terminalId = $terminalId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('Huifu POS %s', $this->terminalId); }
    public function getMerchant(): ?HuifuMerchant { return $this->merchant; }
    public function getMerchantUuid(): ?string { return $this->merchant?->getUuid(); }
    public function setMerchant(HuifuMerchant $merchant): self { $this->merchant = $merchant; return $this->touch(); }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this->touch(); }
    public function getTerminalId(): string { return $this->terminalId; }
    public function setTerminalId(string $terminalId): self { $this->terminalId = $terminalId; return $this->touch(); }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self
    {
        if (!in_array($mode, [self::MODE_LINKED_ORDER, self::MODE_UNCLAIMED], true)) {
            throw new \InvalidArgumentException('Unsupported Huifu POS handling mode.');
        }
        $this->mode = $mode;
        return $this->touch();
    }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this->touch(); }
    public function isEnabled(): bool { return $this->enabled; }
    public function getEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
