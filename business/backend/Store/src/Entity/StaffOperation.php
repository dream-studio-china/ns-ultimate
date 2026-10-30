<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/** Durable command receipt and store-scoped operational audit. No credentials or payment data. */
#[ORM\Entity]
#[ORM\Table(name: 'store_staff_operation')]
#[ORM\UniqueConstraint(name: 'uniq_store_staff_command', columns: ['store_uuid', 'actor_uuid', 'command_uuid'])]
#[ORM\Index(name: 'idx_store_staff_operation_created', columns: ['store_uuid', 'created_at'])]
final class StaffOperation
{
    #[ORM\Id, ORM\Column(type: 'string', length: 36)]
    private string $uuid;

    #[ORM\Column(length: 36)]
    private string $storeUuid;

    #[ORM\Column(length: 36)]
    private string $actorUuid;

    #[ORM\Column(length: 36)]
    private string $commandUuid;

    #[ORM\Column(length: 80)]
    private string $action;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $beforeState;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $result;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $beforeState
     * @param array<string, mixed> $result */
    public function __construct(string $storeUuid, string $actorUuid, string $commandUuid, string $action, string $fingerprint, array $beforeState, array $result)
    {
        $this->uuid = UUID::v4();
        $this->storeUuid = $storeUuid;
        $this->actorUuid = $actorUuid;
        $this->commandUuid = $commandUuid;
        $this->action = $action;
        $this->fingerprint = $fingerprint;
        $this->beforeState = $beforeState;
        $this->result = $result;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getFingerprint(): string { return $this->fingerprint; }
    /** @return array<string, mixed> */
    public function getResult(): array { return $this->result; }
    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['uuid' => $this->uuid, 'actorUuid' => $this->actorUuid, 'commandUuid' => $this->commandUuid,
            'action' => $this->action,
            'createdAt' => $this->createdAt->format(DATE_ATOM)];
    }
}
