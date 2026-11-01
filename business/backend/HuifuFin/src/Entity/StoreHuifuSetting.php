<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'store_huifu_setting')]
#[ORM\UniqueConstraint(name: 'uniq_store_huifu_setting_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_huifu_setting_store', columns: ['store_uuid'])]
class StoreHuifuSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36, unique: true)]
    private string $storeUuid = '';

    #[ORM\ManyToOne(targetEntity: HuifuMerchant::class)]
    #[ORM\JoinColumn(name: 'merchant_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?HuifuMerchant $merchant = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    /** @var list<string> */
    #[ORM\Column(name: 'allowed_methods', type: 'json')]
    private array $allowedMethods = ['jspay'];

    #[ORM\Column(name: 'default_method', type: 'string', length: 32, options: ['default' => 'jspay'])]
    private string $defaultMethod = 'jspay';

    #[ORM\Column(name: 'pos_enabled', type: 'boolean', options: ['default' => false])]
    private bool $posEnabled = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $storeUuid = '', ?HuifuMerchant $merchant = null)
    {
        $this->uuid = UUID::v4();
        $this->storeUuid = $storeUuid;
        $this->merchant = $merchant;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('Store Huifu settings (%s)', $this->storeUuid); }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this->touch(); }
    public function getMerchant(): ?HuifuMerchant { return $this->merchant; }
    public function getMerchantUuid(): ?string { return $this->merchant?->getUuid(); }
    public function setMerchant(?HuifuMerchant $merchant): self { $this->merchant = $merchant; return $this->touch(); }
    public function isEnabled(): bool { return $this->enabled; }
    public function getEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }
    /** @return list<string> */
    public function getAllowedMethods(): array { return $this->allowedMethods; }
    /** @param list<string> $methods */
    public function setAllowedMethods(array $methods): self
    {
        $this->allowedMethods = array_values(array_unique(array_filter(
            $methods,
            static fn (mixed $method): bool => is_string($method) && in_array($method, ['jspay', 'quickpay', 'native'], true),
        )));
        if ($this->allowedMethods === []) {
            throw new \InvalidArgumentException('At least one Huifu payment method is required.');
        }
        if (!in_array($this->defaultMethod, $this->allowedMethods, true)) {
            $this->defaultMethod = $this->allowedMethods[0];
        }

        return $this->touch();
    }
    public function getDefaultMethod(): string { return $this->defaultMethod; }
    public function setDefaultMethod(string $method): self
    {
        if (!in_array($method, $this->allowedMethods, true)) {
            throw new \InvalidArgumentException('The default Huifu payment method must be allowed.');
        }
        $this->defaultMethod = $method;
        return $this->touch();
    }
    public function isPosEnabled(): bool { return $this->posEnabled; }
    public function getPosEnabled(): bool { return $this->posEnabled; }
    public function setPosEnabled(bool $enabled): self { $this->posEnabled = $enabled; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
