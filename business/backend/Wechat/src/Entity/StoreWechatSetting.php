<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'store_wechat_setting')]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_setting_store', columns: ['store_uuid'])]
class StoreWechatSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36, unique: true)]
    private string $storeUuid;

    #[ORM\ManyToOne(targetEntity: WechatApplication::class)]
    #[ORM\JoinColumn(name: 'miniapp_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WechatApplication $miniapp;

    #[ORM\ManyToOne(targetEntity: WechatApplication::class)]
    #[ORM\JoinColumn(name: 'official_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WechatApplication $official;

    #[ORM\ManyToOne(targetEntity: WechatMerchant::class)]
    #[ORM\JoinColumn(name: 'merchant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private WechatMerchant $merchant;

    public function __construct(string $storeUuid, ?WechatApplication $miniapp, ?WechatApplication $official, WechatMerchant $merchant)
    {
        $this->uuid = UUID::v4();
        $this->storeUuid = $storeUuid;
        $this->miniapp = $miniapp;
        $this->official = $official;
        $this->merchant = $merchant;
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this; }
    public function getMiniapp(): ?WechatApplication { return $this->miniapp; }
    public function getMiniappUuid(): ?string { return $this->miniapp?->getUuid(); }
    public function setMiniapp(?WechatApplication $miniapp): self { $this->miniapp = $miniapp; return $this; }
    public function getOfficial(): ?WechatApplication { return $this->official; }
    public function getOfficialUuid(): ?string { return $this->official?->getUuid(); }
    public function setOfficial(?WechatApplication $official): self { $this->official = $official; return $this; }
    public function getMerchant(): WechatMerchant { return $this->merchant; }
    public function getMerchantUuid(): string { return $this->merchant->getUuid(); }
    public function setMerchant(WechatMerchant $merchant): self { $this->merchant = $merchant; return $this; }
}
