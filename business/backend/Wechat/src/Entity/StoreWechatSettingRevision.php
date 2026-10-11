<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable snapshot of the application/merchant revisions a Store binding uses.
 *
 * The binding intentionally pins specific published revisions so that editing a
 * shared profile does not silently change every bound Store.
 */
#[ORM\Entity]
#[ORM\Table(name: 'store_wechat_setting_revision')]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_setting_revision_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_setting_revision_version', columns: ['setting_id', 'revision'])]
#[ORM\Index(name: 'idx_store_wechat_setting_revision_state', columns: ['setting_id', 'state'])]
class StoreWechatSettingRevision
{
    public const STATE_DRAFT = 'draft';
    public const STATE_PUBLISHED = 'published';
    public const STATE_SUPERSEDED = 'superseded';

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

    #[ORM\ManyToOne(targetEntity: StoreWechatSetting::class)]
    #[ORM\JoinColumn(name: 'setting_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StoreWechatSetting $setting;

    #[ORM\Column(type: 'integer')]
    private int $revision = 1;

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

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATE_DRAFT])]
    private string $state = self::STATE_DRAFT;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(StoreWechatSetting $setting, int $revision)
    {
        $this->uuid = UUID::v4();
        $this->setting = $setting;
        $this->revision = $revision;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s r%d', $this->setting->getStoreUuid(), $this->revision);
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getSetting(): StoreWechatSetting { return $this->setting; }
    public function getRevision(): int { return $this->revision; }
    public function getState(): string { return $this->state; }
    public function isPublished(): bool { return $this->state === self::STATE_PUBLISHED; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    public function getMiniappRevision(): ?WechatApplicationRevision { return $this->miniappRevision; }
    public function getOfficialRevision(): ?WechatApplicationRevision { return $this->officialRevision; }
    public function getMerchantRevision(): ?WechatMerchantRevision { return $this->merchantRevision; }

    /** @param array<string, bool> $capabilities */
    public function setCapabilities(array $capabilities): self
    {
        $normalized = [];
        foreach ($capabilities as $capability => $enabled) {
            $normalized[(string) $capability] = (bool) $enabled;
        }
        $this->capabilities = $normalized;
        return $this;
    }

    /** @return array<string, bool> */
    public function getCapabilities(): array { return $this->capabilities; }

    public function supports(string $capability): bool
    {
        return ($this->capabilities[$capability] ?? false) === true;
    }

    public function setMiniappRevision(?WechatApplicationRevision $revision): self
    {
        $this->miniappRevision = $revision;
        return $this;
    }

    public function setOfficialRevision(?WechatApplicationRevision $revision): self
    {
        $this->officialRevision = $revision;
        return $this;
    }

    public function setMerchantRevision(?WechatMerchantRevision $revision): self
    {
        $this->merchantRevision = $revision;
        return $this;
    }

    public function applicationRevisionFor(string $applicationType): ?WechatApplicationRevision
    {
        return match ($applicationType) {
            WechatApplication::TYPE_MINIAPP => $this->miniappRevision,
            WechatApplication::TYPE_OFFICIAL => $this->officialRevision,
            default => null,
        };
    }

    public function publish(): self
    {
        if ($this->state !== self::STATE_DRAFT) {
            throw new \LogicException('Only draft revisions can be published.');
        }
        $this->state = self::STATE_PUBLISHED;
        $this->publishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function supersede(): self
    {
        if ($this->state === self::STATE_PUBLISHED) {
            $this->state = self::STATE_SUPERSEDED;
        }
        return $this;
    }
}
