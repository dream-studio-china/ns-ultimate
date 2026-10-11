<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use Official Account OAuth state.
 *
 * The browser receives an opaque random token. The authoritative state
 * (Store, application revision, approved redirect, expiry, consumption) lives in
 * this row, so a callback cannot substitute a Store or application and a token
 * cannot be replayed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_oauth_state')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_oauth_state_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_oauth_state_nonce', columns: ['nonce'])]
class WechatOAuthState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $nonce = '';

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\ManyToOne(targetEntity: WechatApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'application_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?WechatApplicationRevision $applicationRevision = null;

    #[ORM\Column(name: 'redirect_host', type: 'string', length: 255)]
    private string $redirectHost = '';

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->expiresAt = new \DateTimeImmutable('+5 minutes');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->nonce; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getNonce(): string { return $this->nonce; }
    public function setNonce(string $nonce): self { $this->nonce = $nonce; return $this; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this; }
    public function getApplicationRevision(): ?WechatApplicationRevision { return $this->applicationRevision; }
    public function setApplicationRevision(WechatApplicationRevision $applicationRevision): self { $this->applicationRevision = $applicationRevision; return $this; }
    public function getRedirectHost(): string { return $this->redirectHost; }
    public function setRedirectHost(string $redirectHost): self { $this->redirectHost = $redirectHost; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getConsumedAt(): ?\DateTimeImmutable { return $this->consumedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function consume(): self
    {
        $this->consumedAt = new \DateTimeImmutable();
        return $this;
    }
}
