<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'counter_session')]
#[ORM\Index(name: 'idx_counter_session_open', columns: ['counter_id', 'ended_at'])]
class CounterSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Counter::class)]
    #[ORM\JoinColumn(name: 'counter_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Counter $counter;

    // NULL for historical sessions; a unique key permits only one active session per counter.
    #[ORM\Column(name: 'active_counter_uuid', type: 'string', length: 36, nullable: true, unique: true)]
    private ?string $activeCounterUuid;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(Counter $counter)
    {
        $this->uuid = UUID::v4();
        $this->counter = $counter;
        $this->activeCounterUuid = $counter->getUuid();
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getCounter(): Counter { return $this->counter; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function end(): void
    {
        $this->endedAt ??= new \DateTimeImmutable();
        $this->activeCounterUuid = null;
    }
}
