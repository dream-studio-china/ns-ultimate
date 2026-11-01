<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_provider_profile')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_provider_profile_uuid', columns: ['uuid'])]
class HuifuProviderProfile
{
    public const ENVIRONMENT_PRODUCTION = 'production';
    public const ENVIRONMENT_SANDBOX = 'sandbox';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::ENVIRONMENT_PRODUCTION])]
    private string $environment = self::ENVIRONMENT_PRODUCTION;

    #[ORM\Column(name: 'sys_id', type: 'string', length: 64)]
    private string $sysId = '';

    #[ORM\Column(name: 'product_id', type: 'string', length: 64)]
    private string $productId = '';

    #[ORM\Column(name: 'credentials_cipher', type: 'text')]
    private string $credentialsCipher = '';

    /** @var array<int|string, string>|null */
    #[ORM\Column(name: 'credentials_history', type: 'json', nullable: true)]
    private ?array $credentialsHistory = null;

    #[ORM\Column(name: 'credential_version', type: 'integer', options: ['default' => 1])]
    private int $credentialVersion = 1;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $name = '',
        string $environment = self::ENVIRONMENT_PRODUCTION,
        string $sysId = '',
        string $productId = '',
        string $credentialsCipher = '',
    ) {
        if (!in_array($environment, [self::ENVIRONMENT_PRODUCTION, self::ENVIRONMENT_SANDBOX], true)) {
            throw new \InvalidArgumentException('Unsupported Huifu environment.');
        }
        $this->uuid = UUID::v4();
        $this->name = $name;
        $this->environment = $environment;
        $this->sysId = $sysId;
        $this->productId = $productId;
        $this->credentialsCipher = $credentialsCipher;
        $this->credentialsHistory = $credentialsCipher !== '' ? ['1' => $credentialsCipher] : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function __toString(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this->touch(); }
    public function getEnvironment(): string { return $this->environment; }
    public function setEnvironment(string $environment): self
    {
        if (!in_array($environment, [self::ENVIRONMENT_PRODUCTION, self::ENVIRONMENT_SANDBOX], true)) {
            throw new \InvalidArgumentException('Unsupported Huifu environment.');
        }
        $this->environment = $environment;
        return $this->touch();
    }
    public function getSysId(): string { return $this->sysId; }
    public function setSysId(string $sysId): self { $this->sysId = $sysId; return $this->touch(); }
    public function getProductId(): string { return $this->productId; }
    public function setProductId(string $productId): self { $this->productId = $productId; return $this->touch(); }
    public function getCredentialVersion(): int { return $this->credentialVersion; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** @param array<string, string> $credentials */
    public function setCredentials(array $credentials, HuifuSecretCipher $cipher): self
    {
        if ($this->hasCredentials()) {
            $this->credentialVersion++;
        }
        $this->credentialsCipher = $cipher->encrypt($credentials);
        $history = $this->credentialsHistory ?? [];
        $history[(string) $this->credentialVersion] = $this->credentialsCipher;
        $this->credentialsHistory = $history;

        return $this->touch();
    }

    /** @return array<string, string> */
    public function decryptCredentials(HuifuSecretCipher $cipher): array
    {
        return $cipher->decrypt($this->credentialsCipher);
    }

    /** @return array<string, string> */
    public function decryptCredentialsForVersion(int $version, HuifuSecretCipher $cipher): array
    {
        $history = $this->credentialsHistory ?? [];
        $encrypted = $history[(string) $version] ?? null;
        if (!is_string($encrypted) || $encrypted === '') {
            if ($version !== $this->credentialVersion) {
                throw new \RuntimeException('The Huifu credential version used by this payment is unavailable.');
            }
            $encrypted = $this->credentialsCipher;
        }

        return $cipher->decrypt($encrypted);
    }

    public function hasCredentials(): bool
    {
        return $this->credentialsCipher !== '';
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
