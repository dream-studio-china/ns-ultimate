<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'printer_account')]
#[ORM\UniqueConstraint(name: 'uniq_printer_account_uuid', columns: ['uuid'])]
class PrinterAccount
{
    public const PROVIDER_XPYUN = 'xpyun';
    public const PROVIDER_DELICLOUD = 'delicloud';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(name: 'developer_id', type: 'string', length: 160)]
    private string $developerId;

    #[ORM\Column(type: 'string', length: 32, options: ['default' => self::PROVIDER_XPYUN])]
    private string $provider = self::PROVIDER_XPYUN;

    #[ORM\Column(name: 'credentials_cipher', type: 'text')]
    #[Ignore]
    private string $credentialsCipher;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name = '', string $developerId = '', string $credentialsCipher = '', string $provider = self::PROVIDER_XPYUN)
    {
        $this->uuid = UUID::v4();
        $this->name = $name === '' ? '' : self::required($name, 'name');
        $this->developerId = $developerId === '' ? '' : self::required($developerId, 'developerId');
        $this->credentialsCipher = $credentialsCipher;
        $this->setProvider($provider);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = self::required($name, 'name'); return $this; }
    public function getDeveloperId(): string { return $this->developerId; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): self
    {
        if (!in_array($provider, [self::PROVIDER_XPYUN, self::PROVIDER_DELICLOUD], true)) {
            throw new \InvalidArgumentException('Unsupported printer provider.');
        }
        $this->provider = $provider;
        return $this;
    }
    public function setDeveloperId(string $developerId): self { $this->developerId = self::required($developerId, 'developerId'); return $this; }
    public function getCredentialsCipher(): string { return $this->credentialsCipher; }
    public function setCredentialsCipher(string $cipher): self { $this->credentialsCipher = $cipher; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function __toString(): string
    {
        $label = $this->name !== '' ? $this->name : $this->uuid;
        return sprintf('%s · %s', strtoupper($this->provider), $label);
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') { throw new \InvalidArgumentException(sprintf('%s cannot be empty.', $field)); }
        return $value;
    }
}
