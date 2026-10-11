<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * A WeChat Pay merchant profile.
 *
 * Only ordinary merchant direct mode is implemented. The constructor takes no
 * required arguments so the Core CRUD lifecycle can populate the entity through
 * setters.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_merchant')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_merchant_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_merchant_merchant_id', columns: ['merchant_id'])]
class WechatMerchant
{
    public const MODE_DIRECT = 'direct';
    public const MODE_PARTNER = 'partner';

    /** @var list<string> */
    public const MODES = [self::MODE_DIRECT, self::MODE_PARTNER];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name = '';

    #[ORM\Column(name: 'merchant_id', type: 'string', length: 64)]
    private string $merchantId = '';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::MODE_DIRECT])]
    private string $mode = self::MODE_DIRECT;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this->touch(); }
    public function getMerchantId(): string { return $this->merchantId; }
    public function setMerchantId(string $merchantId): self
    {
        $merchantId = trim($merchantId);
        if ($merchantId === '') {
            throw new \InvalidArgumentException('A WeChat merchant requires a non-empty merchant ID.');
        }
        $this->merchantId = $merchantId;
        return $this->touch();
    }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported WeChat merchant mode "%s".', $mode));
        }
        $this->mode = $mode;
        return $this->touch();
    }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
