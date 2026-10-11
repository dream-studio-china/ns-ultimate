<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * An application-scoped WeChat identity.
 *
 * Identity is keyed by the verified (AppID, OpenID) pair, never by OpenID alone,
 * so identical OpenID strings under different applications never collide. One
 * system User may hold many identities. Session keys are stored encrypted and are
 * never returned through the serializer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_identity')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_identity_app_openid', columns: ['app_id', 'openid'])]
#[ORM\Index(name: 'idx_wechat_identity_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_wechat_identity_unionid', columns: ['unionid'])]
class WechatIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'application_type', type: 'string', length: 20)]
    private string $applicationType = WechatApplication::TYPE_MINIAPP;

    #[ORM\Column(name: 'app_id', type: 'string', length: 64)]
    private string $appId = '';

    #[ORM\Column(type: 'string', length: 64)]
    private string $openid = '';

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $unionid = null;

    #[ORM\Column(name: 'session_key_encrypted', type: 'text', nullable: true)]
    private ?string $sessionKeyEncrypted = null;

    #[ORM\Column(name: 'session_key_key_id', type: 'string', length: 32, nullable: true)]
    private ?string $sessionKeyKeyId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'raw_data', type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column(name: 'last_login_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastLoginAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->lastLoginAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s:%s', $this->appId, $this->openid);
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this->touch(); }
    public function getApplicationType(): string { return $this->applicationType; }
    public function setApplicationType(string $applicationType): self { $this->applicationType = $applicationType; return $this->touch(); }
    public function getAppId(): string { return $this->appId; }
    public function setAppId(string $appId): self { $this->appId = $appId; return $this->touch(); }
    public function getOpenid(): string { return $this->openid; }
    public function setOpenid(string $openid): self { $this->openid = $openid; return $this->touch(); }
    public function getUnionid(): ?string { return $this->unionid; }
    public function setUnionid(?string $unionid): self { $this->unionid = $unionid; return $this->touch(); }
    public function getSessionKeyKeyId(): ?string { return $this->sessionKeyKeyId; }

    /** @return array<string, mixed>|null */
    public function getRawData(): ?array { return $this->rawData; }
    /** @param array<string, mixed>|null $rawData */
    public function setRawData(?array $rawData): self { $this->rawData = $rawData; return $this->touch(); }

    public function getLastLoginAt(): \DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(\DateTimeImmutable $lastLoginAt): self { $this->lastLoginAt = $lastLoginAt; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function hasSessionKey(): bool { return $this->sessionKeyEncrypted !== null; }

    /** Raw encrypted material. Only SecretCipher-aware services may consume this. */
    public function encryptedSessionKey(): ?string { return $this->sessionKeyEncrypted; }

    public function setSessionKeyEncrypted(?string $sessionKeyEncrypted, ?string $keyId): self
    {
        $this->sessionKeyEncrypted = $sessionKeyEncrypted;
        $this->sessionKeyKeyId = $keyId;
        return $this->touch();
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
