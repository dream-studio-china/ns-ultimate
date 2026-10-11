<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * The active WeChat configuration binding for a single Store.
 *
 * A Store has at most one setting. Draft bindings are staged as immutable
 * StoreWechatSettingRevision rows; only a published revision is used at runtime.
 */
#[ORM\Entity]
#[ORM\Table(name: 'store_wechat_setting')]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_setting_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_setting_store_uuid', columns: ['store_uuid'])]
class StoreWechatSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $storeUuid)
    {
        if ($storeUuid === '') {
            throw new \InvalidArgumentException('A store WeChat setting requires a store UUID.');
        }

        $this->uuid = UUID::v4();
        $this->storeUuid = $storeUuid;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->storeUuid; }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
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
