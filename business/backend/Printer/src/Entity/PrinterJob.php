<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'printer_job')]
#[ORM\UniqueConstraint(name: 'uniq_printer_job_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_printer_job_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_printer_job_store_created', columns: ['store_uuid', 'created_at'])]
class PrinterJob
{
    public const STATUS_SUBMITTING = 'submitting';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: PrinterDevice::class)]
    #[ORM\JoinColumn(name: 'device_id', nullable: false, onDelete: 'RESTRICT')]
    private PrinterDevice $device;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $storeUuid;

    #[ORM\Column(type: 'string', length: 20)]
    private string $kind;

    #[ORM\Column(type: 'integer')]
    private int $copies;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'template_snapshot', type: 'json')]
    private array $templateSnapshot;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'data_snapshot', type: 'json')]
    private array $dataSnapshot;

    #[ORM\Column(name: 'rendered_content', type: 'text')]
    private string $renderedContent;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_SUBMITTING;

    #[ORM\Column(name: 'provider_order_id', type: 'string', length: 80, nullable: true)]
    private ?string $providerOrderId = null;

    #[ORM\Column(name: 'error_code', type: 'string', length: 80, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $templateSnapshot @param array<string, mixed> $dataSnapshot */
    public function __construct(PrinterDevice $device, int $copies, array $templateSnapshot, array $dataSnapshot, string $renderedContent, string $idempotencyKey)
    {
        if ($copies < 1 || $copies > 100) { throw new \InvalidArgumentException('Copies must be between 1 and 100.'); }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 50) { throw new \InvalidArgumentException('Idempotency key must contain 1 to 50 characters.'); }
        $this->uuid = UUID::v4();
        $this->device = $device;
        $this->storeUuid = $device->getStoreUuid();
        $this->kind = $device->getKind();
        $this->copies = $copies;
        $this->templateSnapshot = $templateSnapshot;
        $this->dataSnapshot = $dataSnapshot;
        $this->renderedContent = $renderedContent;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 50)]
    private string $idempotencyKey;

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getDevice(): PrinterDevice { return $this->device; }
    public function getStoreUuid(): ?string { return $this->storeUuid; }
    public function getKind(): string { return $this->kind; }
    public function getCopies(): int { return $this->copies; }
    public function getStatus(): string { return $this->status; }
    public function getProviderOrderId(): ?string { return $this->providerOrderId; }
    public function getErrorCode(): ?string { return $this->errorCode; }
    public function getRenderedContent(): string { return $this->renderedContent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }

    /** @return array{kind: string, pages: list<string>, content: string, widthMm: int, heightMm: ?int} */
    public function previewDocument(): array
    {
        $pages = [$this->renderedContent];
        if ($this->kind === PrinterDevice::KIND_LABEL) {
            preg_match_all('/<PAGE><SIZE>\d+,\d+<\/SIZE>(.*?)<\/PAGE>/s', $this->renderedContent, $matches);
            $pages = $matches[1];
        }

        return [
            'kind' => $this->kind,
            'pages' => $pages,
            'content' => $this->renderedContent,
            'widthMm' => $this->templateSnapshot['widthMm'] ?? ($this->kind === PrinterDevice::KIND_RECEIPT ? 80 : 40),
            'heightMm' => $this->kind === PrinterDevice::KIND_LABEL ? ($this->templateSnapshot['heightMm'] ?? 30) : null,
        ];
    }

    public function submitted(string $providerOrderId): self { $this->status = self::STATUS_SUBMITTED; $this->providerOrderId = $providerOrderId; $this->errorCode = null; return $this; }
    public function failed(string $errorCode): self { $this->status = self::STATUS_FAILED; $this->errorCode = substr($errorCode, 0, 80); return $this; }
    public function unknown(): self { $this->status = self::STATUS_UNKNOWN; $this->errorCode = 'transport_outcome_unknown'; return $this; }
    public function markPrinted(): self { $this->status = self::STATUS_PRINTED; return $this; }
}
