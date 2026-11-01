<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_merchant_store_grant')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_merchant_store_grant_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_huifu_merchant_store_grant_pair', columns: ['merchant_id', 'store_uuid'])]
class HuifuMerchantStoreGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: HuifuMerchant::class)]
    #[ORM\JoinColumn(name: 'merchant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?HuifuMerchant $merchant = null;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?HuifuMerchant $merchant = null, string $storeUuid = '')
    {
        $this->uuid = UUID::v4();
        $this->merchant = $merchant;
        $this->storeUuid = $storeUuid;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('Huifu grant (%s)', $this->storeUuid); }
    public function getMerchant(): ?HuifuMerchant { return $this->merchant; }
    public function getMerchantUuid(): ?string { return $this->merchant?->getUuid(); }
    public function setMerchant(HuifuMerchant $merchant): self { $this->merchant = $merchant; return $this; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
