<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable credential configuration for a WeChat application profile.
 *
 * Encrypted credential columns are intentionally exposed only through non-getter
 * methods so the serializer never emits ciphertext. All secret material is
 * encrypted by SecretCipher before it reaches this entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_application_revision')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_application_revision_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_application_revision_version', columns: ['application_id', 'revision'])]
#[ORM\Index(name: 'idx_wechat_application_revision_state', columns: ['application_id', 'state'])]
class WechatApplicationRevision
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

    #[ORM\ManyToOne(targetEntity: WechatApplication::class)]
    #[ORM\JoinColumn(name: 'application_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WechatApplication $application;

    #[ORM\Column(type: 'integer')]
    private int $revision = 1;

    #[ORM\Column(name: 'secret_encrypted', type: 'text', nullable: true)]
    private ?string $secretEncrypted = null;

    #[ORM\Column(name: 'official_token_encrypted', type: 'text', nullable: true)]
    private ?string $officialTokenEncrypted = null;

    #[ORM\Column(name: 'official_aes_key_encrypted', type: 'text', nullable: true)]
    private ?string $officialAesKeyEncrypted = null;

    #[ORM\Column(name: 'key_id', type: 'string', length: 32, nullable: true)]
    private ?string $keyId = null;

    /** @var list<string>|null */
    #[ORM\Column(name: 'redirect_hosts', type: 'json', nullable: true)]
    private ?array $redirectHosts = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATE_DRAFT])]
    private string $state = self::STATE_DRAFT;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(WechatApplication $application, int $revision)
    {
        $this->uuid = UUID::v4();
        $this->application = $application;
        $this->revision = $revision;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s r%d', $this->application->getName(), $this->revision);
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getApplication(): WechatApplication { return $this->application; }
    public function getRevision(): int { return $this->revision; }
    public function getState(): string { return $this->state; }
    public function isPublished(): bool { return $this->state === self::STATE_PUBLISHED; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    public function hasSecret(): bool { return $this->secretEncrypted !== null; }
    public function hasOfficialToken(): bool { return $this->officialTokenEncrypted !== null; }
    public function hasOfficialAesKey(): bool { return $this->officialAesKeyEncrypted !== null; }

    /** @return list<string> */
    public function getRedirectHosts(): array { return $this->redirectHosts ?? []; }

    /** @param list<string> $hosts */
    public function setRedirectHosts(array $hosts): self
    {
        $this->redirectHosts = array_values(array_unique(array_filter(
            array_map(static fn (string $host): string => strtolower(trim($host)), $hosts),
            static fn (string $host): bool => $host !== '',
        )));
        return $this;
    }

    /**
     * Raw encrypted material. Only SecretCipher-aware services may consume this.
     */
    public function encryptedSecret(): ?string { return $this->secretEncrypted; }

    /** Raw encrypted material. Only SecretCipher-aware services may consume this. */
    public function encryptedOfficialToken(): ?string { return $this->officialTokenEncrypted; }

    /** Raw encrypted material. Only SecretCipher-aware services may consume this. */
    public function encryptedOfficialAesKey(): ?string { return $this->officialAesKeyEncrypted; }

    public function getKeyId(): ?string { return $this->keyId; }

    public function setEncryptedCredentials(
        string $keyId,
        ?string $secretEncrypted,
        ?string $officialTokenEncrypted,
        ?string $officialAesKeyEncrypted,
    ): self {
        $this->keyId = $keyId;
        $this->secretEncrypted = $secretEncrypted;
        $this->officialTokenEncrypted = $officialTokenEncrypted;
        $this->officialAesKeyEncrypted = $officialAesKeyEncrypted;
        return $this;
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
