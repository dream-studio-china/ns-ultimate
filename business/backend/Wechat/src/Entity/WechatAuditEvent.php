<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only, redacted WeChat configuration security audit event.
 *
 * Written by services; never updated or deleted through the API. Context values
 * are redacted before storage.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wechat_audit_event')]
#[ORM\UniqueConstraint(name: 'uniq_wechat_audit_event_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_wechat_audit_event_object', columns: ['object_type', 'object_uuid'])]
#[ORM\Index(name: 'idx_wechat_audit_event_store', columns: ['store_uuid'])]
class WechatAuditEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'actor_user_id', type: 'integer', nullable: true)]
    private ?int $actorUserId = null;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36, nullable: true)]
    private ?string $storeUuid = null;

    #[ORM\Column(name: 'object_type', type: 'string', length: 40)]
    private string $objectType = '';

    #[ORM\Column(name: 'object_uuid', type: 'string', length: 64)]
    private string $objectUuid = '';

    #[ORM\Column(type: 'string', length: 40)]
    private string $action = '';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'success'])]
    private string $result = 'success';

    #[ORM\Column(name: 'request_id', type: 'string', length: 64, nullable: true)]
    private ?string $requestId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s %s %s', $this->action, $this->objectType, $this->objectUuid);
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getActorUserId(): ?int { return $this->actorUserId; }
    public function setActorUserId(?int $actorUserId): self { $this->actorUserId = $actorUserId; return $this; }
    public function getStoreUuid(): ?string { return $this->storeUuid; }
    public function setStoreUuid(?string $storeUuid): self { $this->storeUuid = $storeUuid; return $this; }
    public function getObjectType(): string { return $this->objectType; }
    public function setObjectType(string $objectType): self { $this->objectType = $objectType; return $this; }
    public function getObjectUuid(): string { return $this->objectUuid; }
    public function setObjectUuid(string $objectUuid): self { $this->objectUuid = $objectUuid; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
    public function getResult(): string { return $this->result; }
    public function setResult(string $result): self { $this->result = $result; return $this; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function setRequestId(?string $requestId): self { $this->requestId = $requestId; return $this; }
    /** @return array<string, mixed>|null */
    public function getContext(): ?array { return $this->context; }
    /** @param array<string, mixed>|null $context */
    public function setContext(?array $context): self { $this->context = $context; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
