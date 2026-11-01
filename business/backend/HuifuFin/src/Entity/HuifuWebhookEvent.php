<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'huifu_webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_huifu_webhook_event_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_huifu_webhook_event_key', columns: ['event_key'])]
class HuifuWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'event_key', type: 'string', length: 191, unique: true)]
    private string $eventKey = '';

    #[ORM\Column(type: 'string', length: 32)]
    private string $type = 'payment';

    #[ORM\ManyToOne(targetEntity: HuifuPaymentAttempt::class)]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?HuifuPaymentAttempt $attempt = null;

    #[ORM\Column(name: 'body_hash', type: 'string', length: 64)]
    private string $bodyHash = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $processed = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /** @param array<string, mixed>|null $payload */
    public function __construct(
        string $eventKey = '',
        string $type = 'payment',
        ?HuifuPaymentAttempt $attempt = null,
        string $bodyHash = '',
        ?array $payload = null,
    ) {
        $this->uuid = UUID::v4();
        $this->eventKey = $eventKey;
        $this->type = $type;
        $this->attempt = $attempt;
        $this->bodyHash = $bodyHash;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function __toString(): string { return sprintf('Huifu webhook %s', $this->eventKey); }
    public function getEventKey(): string { return $this->eventKey; }
    public function getType(): string { return $this->type; }
    public function getAttempt(): ?HuifuPaymentAttempt { return $this->attempt; }
    public function getBodyHash(): string { return $this->bodyHash; }
    /** @return array<string, mixed>|null */
    public function getPayload(): ?array { return $this->payload; }
    public function isProcessed(): bool { return $this->processed; }
    public function getProcessed(): bool { return $this->processed; }
    public function getError(): ?string { return $this->error; }
    public function markProcessed(): self
    {
        $this->processed = true;
        $this->processedAt = new \DateTimeImmutable();
        $this->error = null;
        return $this;
    }
    public function markError(string $error): self
    {
        $this->error = $error;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }
}
