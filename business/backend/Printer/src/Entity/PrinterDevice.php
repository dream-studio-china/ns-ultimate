<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'printer_device')]
#[ORM\UniqueConstraint(name: 'uniq_printer_device_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_printer_device_account_sn', columns: ['account_id', 'serial_number'])]
#[ORM\Index(name: 'idx_printer_device_store_kind', columns: ['store_uuid', 'kind'])]
class PrinterDevice
{
    public const KIND_RECEIPT = 'receipt';
    public const KIND_LABEL = 'label';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: PrinterAccount::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'RESTRICT')]
    private PrinterAccount $account;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $storeUuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(name: 'serial_number', type: 'string', length: 80)]
    private string $serialNumber;

    #[ORM\Column(type: 'string', length: 20)]
    private string $kind;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $model;

    #[ORM\Column(name: 'width_mm', type: 'integer', nullable: true)]
    private ?int $widthMm;

    #[ORM\Column(name: 'height_mm', type: 'integer', nullable: true)]
    private ?int $heightMm;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'provider_status', type: 'integer', nullable: true)]
    private ?int $providerStatus = null;

    #[ORM\Column(name: 'status_checked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $statusCheckedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(PrinterAccount $account, string $name, string $serialNumber, string $kind, ?string $storeUuid = null, ?string $model = null, ?int $widthMm = null, ?int $heightMm = null)
    {
        if (!in_array($kind, [self::KIND_RECEIPT, self::KIND_LABEL], true)) { throw new \InvalidArgumentException('Printer kind must be receipt or label.'); }
        if ($kind === self::KIND_LABEL && ($widthMm === null || $heightMm === null || $widthMm < 10 || $widthMm > 200 || $heightMm < 10 || $heightMm > 200)) {
            throw new \InvalidArgumentException('Label printers require width and height between 10 and 200 mm.');
        }
        $this->uuid = UUID::v4();
        $this->account = $account;
        $this->name = self::required($name, 'name');
        $this->serialNumber = self::required($serialNumber, 'serialNumber');
        $this->kind = $kind;
        $this->storeUuid = $storeUuid;
        $this->model = $model === null ? null : trim($model);
        $this->widthMm = $widthMm;
        $this->heightMm = $heightMm;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getAccount(): PrinterAccount { return $this->account; }
    public function getStoreUuid(): ?string { return $this->storeUuid; }
    public function getName(): string { return $this->name; }
    public function getSerialNumber(): string { return $this->serialNumber; }
    public function getKind(): string { return $this->kind; }
    public function getModel(): ?string { return $this->model; }
    public function getWidthMm(): ?int { return $this->widthMm; }
    public function getHeightMm(): ?int { return $this->heightMm; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getProviderStatus(): ?int { return $this->providerStatus; }
    public function getStatusCheckedAt(): ?\DateTimeImmutable { return $this->statusCheckedAt; }
    public function updateProviderStatus(int $status): self
    {
        if (!in_array($status, [0, 1, 2], true)) { throw new \InvalidArgumentException('Unknown Xpyun printer status.'); }
        $this->providerStatus = $status;
        $this->statusCheckedAt = new \DateTimeImmutable();
        return $this;
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') { throw new \InvalidArgumentException(sprintf('%s cannot be empty.', $field)); }
        return $value;
    }
}
