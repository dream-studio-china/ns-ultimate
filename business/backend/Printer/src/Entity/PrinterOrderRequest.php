<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'printer_order_request')]
#[ORM\UniqueConstraint(name: 'uniq_printer_order_request_key', columns: ['request_key'])]
#[ORM\Index(name: 'idx_printer_order_request_pending', columns: ['status', 'created_at'])]
class PrinterOrderRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPATCHING = 'dispatching';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'request_key', type: 'string', length: 64, unique: true)]
    private string $requestKey;

    #[ORM\Column(name: 'order_uuid', type: 'string', length: 36)]
    private string $orderUuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid;

    #[ORM\Column(name: 'case_key', type: 'string', length: 64)]
    private string $caseKey;

    #[ORM\Column(name: 'event_trigger', type: 'string', length: 20)]
    private string $trigger;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $configuration;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'print_data', type: 'json')]
    private array $printData;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $printData
     */
    public function __construct(string $requestKey, string $orderUuid, string $storeUuid, string $caseKey, string $trigger, array $configuration, array $printData)
    {
        $this->uuid = UUID::v4();
        $this->requestKey = $requestKey;
        $this->orderUuid = $orderUuid;
        $this->storeUuid = $storeUuid;
        $this->caseKey = $caseKey;
        $this->trigger = $trigger;
        $this->configuration = $configuration;
        $this->printData = $printData;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getRequestKey(): string { return $this->requestKey; }
    public function getOrderUuid(): string { return $this->orderUuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getCaseKey(): string { return $this->caseKey; }
    public function getTrigger(): string { return $this->trigger; }
    /** @return array<string, mixed> */ public function getConfiguration(): array { return $this->configuration; }
    /** @return array<string, mixed> */ public function getPrintData(): array { return $this->printData; }
    public function getStatus(): string { return $this->status; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function markProcessed(): self { $this->status = self::STATUS_PROCESSED; $this->errorMessage = null; return $this; }
    public function markFailed(string $message): self { $this->status = self::STATUS_FAILED; $this->errorMessage = substr($message, 0, 4000); return $this; }
}
