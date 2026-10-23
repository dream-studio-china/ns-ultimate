<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Legacy receipt mapping retained for deployed data; no active assistant execution service uses it. */
#[ORM\Entity]
#[ORM\Table(name: 'admin_agent_operation')]
final class AgentOperation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $actor;

    #[ORM\Column(type: 'string', length: 16)]
    private string $action;

    #[ORM\Column(type: 'string', length: 120)]
    private string $entityKey;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $targetId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $beforeHash;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $changes;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $fields;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = 'pending';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @param array<string, mixed> $changes */
    public function __construct(string $actor, string $entityKey, string $action, ?string $targetId, string $beforeHash, array $changes)
    {
        $this->id = bin2hex(random_bytes(32));
        $this->actor = $actor;
        $this->entityKey = $entityKey;
        $this->action = $action;
        $this->targetId = $targetId;
        $this->beforeHash = $beforeHash;
        $this->changes = $changes;
        $this->fields = array_keys($changes);
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+5 minutes');
    }

    public function getId(): string { return $this->id; }
    public function getActor(): string { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getEntityKey(): string { return $this->entityKey; }
    public function getTargetId(): ?string { return $this->targetId; }
    public function getBeforeHash(): string { return $this->beforeHash; }
    /** @return array<string, mixed> */
    public function getChanges(): array { return $this->changes; }
    public function getStatus(): string { return $this->status; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    public function complete(string $targetId): void
    {
        $this->targetId = $targetId;
        $this->status = 'succeeded';
        $this->completedAt = new \DateTimeImmutable();
        // Receipts retain actor/action/field names, not content values.
        $this->changes = [];
    }

    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->changes = [];
        $this->completedAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        return ['operationId' => $this->id, 'entity' => $this->entityKey, 'action' => $this->action,
            'identifier' => $this->targetId, 'status' => $this->status === 'pending' && $this->expiresAt <= new \DateTimeImmutable() ? 'expired' : $this->status, 'fields' => $this->fields,
            'createdAt' => $this->createdAt->format(DATE_ATOM), 'expiresAt' => $this->expiresAt->format(DATE_ATOM),
            'completedAt' => $this->completedAt?->format(DATE_ATOM)];
    }
}
