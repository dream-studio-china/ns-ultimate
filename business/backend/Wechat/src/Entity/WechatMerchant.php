<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wechat_merchant')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_merchant_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_wechat_merchant_profile', columns: ['payment_mode', 'merchant_id', 'service_provider_app_id', 'sub_merchant_id'])]
class WechatMerchant
{
    public const PAYMENT_MODE_DIRECT = 'direct';
    public const PAYMENT_MODE_SERVICE_PROVIDER = 'service_provider';

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

    #[ORM\Column(name: 'payment_mode', type: 'string', length: 32, options: ['default' => self::PAYMENT_MODE_DIRECT])]
    private string $paymentMode = self::PAYMENT_MODE_DIRECT;

    #[ORM\Column(name: 'service_provider_app_id', type: 'string', length: 128, options: ['default' => ''])]
    private string $serviceProviderAppId = '';

    #[ORM\Column(name: 'sub_merchant_id', type: 'string', length: 64, options: ['default' => ''])]
    private string $subMerchantId = '';

    #[ORM\Column(name: 'credentials_cipher', type: 'text')]
    private string $credentialsCipher = '';

    public function __construct(string $name = '', string $merchantId = '', string $credentialsCipher = '')
    {
        $this->uuid = UUID::v4();
        $this->name = $name;
        $this->merchantId = $merchantId;
        $this->credentialsCipher = $credentialsCipher;
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function __toString(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getMerchantId(): string { return $this->merchantId; }
    public function setMerchantId(string $merchantId): self { $this->merchantId = $merchantId; return $this; }
    public function getPaymentMode(): string { return $this->paymentMode; }
    public function setPaymentMode(string $paymentMode): self { $this->paymentMode = $paymentMode; return $this; }
    public function getServiceProviderAppId(): ?string { return $this->serviceProviderAppId === '' ? null : $this->serviceProviderAppId; }
    public function setServiceProviderAppId(?string $appId): self { $this->serviceProviderAppId = $appId ?? ''; return $this; }
    public function getSubMerchantId(): ?string { return $this->subMerchantId === '' ? null : $this->subMerchantId; }
    public function setSubMerchantId(?string $subMerchantId): self { $this->subMerchantId = $subMerchantId ?? ''; return $this; }
    public function setCredentialsCipher(string $cipher): self { $this->credentialsCipher = $cipher; return $this; }
    /** @return array<string, string> */
    public function decryptCredentials(\NsUltimate\Business\Wechat\Service\WechatSecretCipher $cipher): array { return $cipher->decrypt($this->credentialsCipher); }
}
