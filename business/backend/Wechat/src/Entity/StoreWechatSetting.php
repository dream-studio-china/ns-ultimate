<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * The live WeChat configuration binding for a single Store.
 *
 * A Store has at most one setting. The binding pins specific published
 * application/merchant revisions, so editing a shared profile does not silently
 * change every bound Store. Binding history and draft/publication staging are
 * deferred; the immutability guarantee comes from the pinned revisions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'store_wechat_setting')]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_setting_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_setting_store_uuid', columns: ['store_uuid'])]
class StoreWechatSetting
{
    public const CAP_MINIAPP_LOGIN = 'miniapp_login';
    public const CAP_OFFICIAL_OAUTH = 'official_oauth';
    public const CAP_PAYMENT_JSAPI = 'payment_jsapi';
    public const CAP_PAYMENT_NATIVE = 'payment_native';

    /** @var array<string, string> capability => required application type */
    public const CAPABILITY_APPLICATION_TYPE = [
        self::CAP_MINIAPP_LOGIN => WechatApplication::TYPE_MINIAPP,
        self::CAP_OFFICIAL_OAUTH => WechatApplication::TYPE_OFFICIAL,
        self::CAP_PAYMENT_JSAPI => WechatApplication::TYPE_MINIAPP,
        self::CAP_PAYMENT_NATIVE => WechatApplication::TYPE_MINIAPP,
    ];

    /** @var list<string> */
    public const PAYMENT_CAPABILITIES = [self::CAP_PAYMENT_JSAPI, self::CAP_PAYMENT_NATIVE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\ManyToOne(targetEntity: WechatApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'miniapp_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WechatApplicationRevision $miniappRevision = null;

    #[ORM\ManyToOne(targetEntity: WechatApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'official_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WechatApplicationRevision $officialRevision = null;

    #[ORM\ManyToOne(targetEntity: WechatMerchantRevision::class)]
    #[ORM\JoinColumn(name: 'merchant_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?WechatMerchantRevision $merchantRevision = null;

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $capabilities = [];

    #[ORM\Column(name: 'sub_merchant_id', type: 'string', length: 64, nullable: true)]
    private ?string $subMerchantId = null;

    #[ORM\Column(name: 'sub_app_id', type: 'string', length: 64, nullable: true)]
    private ?string $subAppId = null;

    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer')]
    private int $lockVersion = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->storeUuid; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self
    {
        if ($storeUuid === '') {
            throw new \InvalidArgumentException('A store WeChat setting requires a store UUID.');
        }
        $this->storeUuid = $storeUuid;
        return $this->touch();
    }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function getMiniappRevision(): ?WechatApplicationRevision { return $this->miniappRevision; }
    public function getOfficialRevision(): ?WechatApplicationRevision { return $this->officialRevision; }
    public function getMerchantRevision(): ?WechatMerchantRevision { return $this->merchantRevision; }

    public function setMiniappRevision(?WechatApplicationRevision $revision): self { $this->miniappRevision = $revision; return $this->touch(); }
    public function setOfficialRevision(?WechatApplicationRevision $revision): self { $this->officialRevision = $revision; return $this->touch(); }
    public function setMerchantRevision(?WechatMerchantRevision $revision): self { $this->merchantRevision = $revision; return $this->touch(); }

    /** @param array<string, bool> $capabilities */
    public function setCapabilities(array $capabilities): self
    {
        $normalized = [];
        foreach ($capabilities as $capability => $enabled) {
            $normalized[(string) $capability] = (bool) $enabled;
        }
        $this->capabilities = $normalized;
        return $this->touch();
    }

    /** @return array<string, bool> */
    public function getCapabilities(): array { return $this->capabilities; }

    public function getSubMerchantId(): ?string { return $this->subMerchantId; }
    public function setSubMerchantId(?string $subMerchantId): self { $this->subMerchantId = $subMerchantId; return $this->touch(); }
    public function getSubAppId(): ?string { return $this->subAppId; }
    public function setSubAppId(?string $subAppId): self { $this->subAppId = $subAppId; return $this->touch(); }

    public function supports(string $capability): bool
    {
        return ($this->capabilities[$capability] ?? false) === true;
    }

    public function applicationRevisionFor(string $applicationType): ?WechatApplicationRevision
    {
        return match ($applicationType) {
            WechatApplication::TYPE_MINIAPP => $this->miniappRevision,
            WechatApplication::TYPE_OFFICIAL => $this->officialRevision,
            default => null,
        };
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
