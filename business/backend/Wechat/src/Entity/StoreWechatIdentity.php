<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'store_wechat_identity')]
#[ORM\UniqueConstraint(name: 'uniq_store_wechat_identity_app_openid', columns: ['app_id', 'openid'])]
class StoreWechatIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'app_id', type: 'string', length: 128)]
    private string $appId;

    #[ORM\Column(type: 'string', length: 128)]
    private string $openid;

    #[ORM\Column(name: 'app_type', type: 'string', length: 20)]
    private string $appType;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $appId, string $openid, string $appType)
    {
        $this->uuid = UUID::v4();
        $this->user = $user;
        $this->appId = $appId;
        $this->openid = $openid;
        $this->appType = $appType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('WeChat %s identity (%s)', $this->appType, substr($this->uuid, 0, 8)); }
    public function getUser(): User { return $this->user; }
    public function getAppId(): string { return $this->appId; }
    public function getOpenid(): string { return $this->openid; }
    public function getAppType(): string { return $this->appType; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
