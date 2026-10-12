<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable credential configuration for a WeChat Pay merchant profile.
 *
 * Encrypted credential columns are exposed only through non-getter methods so the
 * serializer never emits ciphertext.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_merchant_revision')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_merchant_revision_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_merchant_revision_version', columns: ['merchant_profile_id', 'revision'])]
#[ORM\Index(name: 'idx_wechat_merchant_revision_state', columns: ['merchant_profile_id', 'state'])]
class WechatMerchantRevision
{
    public const STATE_DRAFT = 'draft';
    public const STATE_PUBLISHED = 'published';
    public const STATE_SUPERSEDED = 'superseded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: WechatMerchant::class)]
    #[ORM\JoinColumn(name: 'merchant_profile_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?WechatMerchant $merchant = null;

    #[ORM\Column(type: 'integer')]
    private int $revision = 1;

    #[ORM\Column(name: 'mode', type: 'string', length: 20, options: ['default' => WechatMerchant::MODE_DIRECT])]
    private string $mode = WechatMerchant::MODE_DIRECT;

    #[ORM\Column(name: 'api_v3_key_encrypted', type: 'text', nullable: true)]
    private ?string $apiV3KeyEncrypted = null;

    #[ORM\Column(name: 'private_key_encrypted', type: 'text', nullable: true)]
    private ?string $privateKeyEncrypted = null;

    #[ORM\Column(name: 'certificate_encrypted', type: 'text', nullable: true)]
    private ?string $certificateEncrypted = null;

    #[ORM\Column(name: 'certificate_serial', type: 'string', length: 128, nullable: true)]
    private ?string $certificateSerial = null;

    #[ORM\Column(name: 'platform_public_key_id', type: 'string', length: 128, nullable: true)]
    private ?string $platformPublicKeyId = null;

    #[ORM\Column(name: 'platform_public_key_encrypted', type: 'text', nullable: true)]
    private ?string $platformPublicKeyEncrypted = null;

    #[ORM\Column(name: 'key_id', type: 'string', length: 32, nullable: true)]
    private ?string $keyId = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATE_DRAFT])]
    private string $state = self::STATE_DRAFT;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $revoked = false;

    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer')]
    private int $lockVersion = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s r%d', $this->merchant?->getName() ?? '', $this->revision);
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getMerchant(): ?WechatMerchant { return $this->merchant; }
    public function setMerchant(?WechatMerchant $merchant): self { $this->merchant = $merchant; return $this; }
    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $revision): self { $this->revision = $revision; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self { $this->mode = $mode; return $this; }
    public function getState(): string { return $this->state; }
    public function isPublished(): bool { return $this->state === self::STATE_PUBLISHED; }
    public function isRevoked(): bool { return $this->revoked; }
    public function revoke(): self { $this->revoked = true; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getCertificateSerial(): ?string { return $this->certificateSerial; }
    public function setCertificateSerial(?string $certificateSerial): self { $this->certificateSerial = $certificateSerial; return $this; }
    public function getPlatformPublicKeyId(): ?string { return $this->platformPublicKeyId; }
    public function getKeyId(): ?string { return $this->keyId; }

    public function isApiV3KeyConfigured(): bool { return $this->apiV3KeyEncrypted !== null; }
    public function isPrivateKeyConfigured(): bool { return $this->privateKeyEncrypted !== null; }
    public function isCertificateConfigured(): bool { return $this->certificateEncrypted !== null; }
    public function isPlatformPublicKeyConfigured(): bool { return $this->platformPublicKeyEncrypted !== null; }

    /** Raw encrypted material. Only SecretCipher-aware services may consume this. */
    public function encryptedApiV3Key(): ?string { return $this->apiV3KeyEncrypted; }

    /** Raw encrypted material. Only SecretCipher-aware services may consume this. */
    public function encryptedPrivateKey(): ?string { return $this->privateKeyEncrypted; }

    /** Raw encrypted material. Only SecretCipher-aware services may consume this. */
    public function encryptedCertificate(): ?string { return $this->certificateEncrypted; }

    /** Raw encrypted material. Only SecretCipher-aware services may consume this. */
    public function encryptedPlatformPublicKey(): ?string { return $this->platformPublicKeyEncrypted; }

    public function setKeyId(?string $keyId): self { $this->keyId = $keyId; return $this; }
    public function setApiV3KeyEncrypted(?string $value): self { $this->apiV3KeyEncrypted = $value; return $this; }
    public function setPrivateKeyEncrypted(?string $value): self { $this->privateKeyEncrypted = $value; return $this; }
    public function setCertificateEncrypted(?string $value): self { $this->certificateEncrypted = $value; return $this; }
    public function setPlatformPublicKeyId(?string $value): self { $this->platformPublicKeyId = $value; return $this; }
    public function setPlatformPublicKeyEncrypted(?string $value): self { $this->platformPublicKeyEncrypted = $value; return $this; }

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
