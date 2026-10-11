<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * A store-configurable WeChat application profile (Mini Program or Official Account).
 *
 * Credentials live on immutable revisions; this profile only stores the stable
 * identity. The AppID is stable for the lifetime of the profile and cannot be
 * changed in place.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_application')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_application_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_application_app_id', columns: ['app_id'])]
class WechatApplication
{
    public const TYPE_MINIAPP = 'miniapp';
    public const TYPE_OFFICIAL = 'official';

    /** @var list<string> */
    public const TYPES = [self::TYPE_MINIAPP, self::TYPE_OFFICIAL];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 20)]
    private string $type = self::TYPE_MINIAPP;

    #[ORM\Column(name: 'app_id', type: 'string', length: 64)]
    private string $appId = '';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $name, string $type, string $appId)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported WeChat application type "%s".', $type));
        }
        if (trim($appId) === '') {
            throw new \InvalidArgumentException('A WeChat application requires a non-empty AppID.');
        }

        $this->uuid = UUID::v4();
        $this->name = $name;
        $this->type = $type;
        $this->appId = trim($appId);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this->touch(); }
    public function getType(): string { return $this->type; }
    public function getAppId(): string { return $this->appId; }
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
