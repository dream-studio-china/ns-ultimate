<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_merchant')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_merchant_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_huifu_merchant_provider_huifu', columns: ['provider_profile_id', 'huifu_id'])]
class HuifuMerchant
{
    public const CAPABILITY_JSPAY = 'jspay';
    public const CAPABILITY_QUICKPAY = 'quickpay';
    public const CAPABILITY_NATIVE = 'native';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: HuifuProviderProfile::class)]
    #[ORM\JoinColumn(name: 'provider_profile_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?HuifuProviderProfile $provider = null;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name = '';

    #[ORM\Column(name: 'huifu_id', type: 'string', length: 64)]
    private string $huifuId = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $capabilities = [self::CAPABILITY_JSPAY];

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @param list<string> $capabilities */
    public function __construct(
        string $name = '',
        string $huifuId = '',
        ?HuifuProviderProfile $provider = null,
        array $capabilities = [self::CAPABILITY_JSPAY],
    ) {
        $this->uuid = UUID::v4();
        $this->name = $name;
        $this->huifuId = $huifuId;
        $this->provider = $provider;
        $this->capabilities = self::normalizeCapabilities($capabilities);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function __toString(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this->touch(); }
    public function getProvider(): HuifuProviderProfile
    {
        return $this->provider ?? throw new \LogicException('Huifu merchant has no provider profile.');
    }
    public function getProviderUuid(): ?string { return $this->provider?->getUuid(); }
    public function setProvider(HuifuProviderProfile $provider): self { $this->provider = $provider; return $this->touch(); }
    public function getHuifuId(): string { return $this->huifuId; }
    public function setHuifuId(string $huifuId): self { $this->huifuId = $huifuId; return $this->touch(); }
    /** @return list<string> */
    public function getCapabilities(): array { return $this->capabilities; }
    /** @param list<string> $capabilities */
    public function setCapabilities(array $capabilities): self
    {
        $this->capabilities = self::normalizeCapabilities($capabilities);
        return $this->touch();
    }
    public function allows(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
    public function isEnabled(): bool { return $this->enabled; }
    public function getEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** @param list<string> $capabilities
     *  @return list<string> */
    private static function normalizeCapabilities(array $capabilities): array
    {
        $allowed = [self::CAPABILITY_JSPAY, self::CAPABILITY_QUICKPAY, self::CAPABILITY_NATIVE];
        $normalized = array_values(array_unique(array_filter(
            $capabilities,
            static fn (mixed $capability): bool => is_string($capability) && in_array($capability, $allowed, true),
        )));
        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one Huifu payment capability is required.');
        }

        return $normalized;
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
