<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wechat_application')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_application_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_application_app_id', columns: ['app_id'])]
class WechatApplication
{
    public const TYPE_MINIAPP = 'miniapp';
    public const TYPE_OFFICIAL = 'official';

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

    #[ORM\Column(name: 'app_id', type: 'string', length: 128, unique: true)]
    private string $appId = '';

    #[ORM\Column(name: 'credentials_cipher', type: 'text')]
    private string $credentialsCipher = '';

    public function __construct(string $name = '', string $type = self::TYPE_MINIAPP, string $appId = '', string $credentialsCipher = '')
    {
        if (!in_array($type, [self::TYPE_MINIAPP, self::TYPE_OFFICIAL], true)) {
            throw new \InvalidArgumentException('Unsupported WeChat application type.');
        }
        $this->uuid = UUID::v4();
        $this->name = $name;
        $this->type = $type;
        $this->appId = $appId;
        $this->credentialsCipher = $credentialsCipher;
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self
    {
        if (!in_array($type, [self::TYPE_MINIAPP, self::TYPE_OFFICIAL], true)) {
            throw new \InvalidArgumentException('Unsupported WeChat application type.');
        }
        $this->type = $type;
        return $this;
    }
    public function getAppId(): string { return $this->appId; }
    public function setAppId(string $appId): self { $this->appId = $appId; return $this; }
    public function setCredentialsCipher(string $cipher): self { $this->credentialsCipher = $cipher; return $this; }
    /** @return array<string, string> */
    public function decryptCredentials(\NsUltimate\Business\Wechat\Service\WechatSecretCipher $cipher): array { return $cipher->decrypt($this->credentialsCipher); }
}
