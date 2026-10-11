<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * Grants a Store the right to bind a shared application or merchant profile.
 *
 * Store operators may only bind profiles explicitly granted to their Store;
 * platform administrators bypass this check.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_profile_grant')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_profile_grant_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_profile_grant_scope', columns: ['profile_type', 'profile_uuid', 'store_uuid'])]
#[ORM\Index(name: 'idx_wechat_profile_grant_store', columns: ['store_uuid'])]
class WechatProfileGrant
{
    public const TYPE_APPLICATION = 'application';
    public const TYPE_MERCHANT = 'merchant';

    /** @var list<string> */
    public const TYPES = [self::TYPE_APPLICATION, self::TYPE_MERCHANT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'profile_type', type: 'string', length: 20)]
    private string $profileType = self::TYPE_APPLICATION;

    #[ORM\Column(name: 'profile_uuid', type: 'string', length: 36)]
    private string $profileUuid = '';

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s:%s@%s', $this->profileType, $this->profileUuid, $this->storeUuid);
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getProfileType(): string { return $this->profileType; }
    public function setProfileType(string $profileType): self
    {
        if (!in_array($profileType, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported profile type "%s".', $profileType));
        }
        $this->profileType = $profileType;
        return $this;
    }
    public function getProfileUuid(): string { return $this->profileUuid; }
    public function setProfileUuid(string $profileUuid): self { $this->profileUuid = $profileUuid; return $this; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
