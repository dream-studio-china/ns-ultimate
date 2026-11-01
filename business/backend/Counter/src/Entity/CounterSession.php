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

    #[ORM\Column(name: 'participant_count', type: 'integer', nullable: true)]
    private ?int $participantCount = null;

    #[ORM\Column(name: 'notes', type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $revision = 0;

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
    public function getParticipantCount(): ?int { return $this->participantCount; }
    public function getNotes(): ?string { return $this->notes; }
    public function getRevision(): int { return $this->revision; }
    public function updateSession(int $participantCount, string $notes): void
    {
        if ($this->endedAt !== null) throw new \LogicException('An ended counter session cannot be edited.');
        if ($participantCount < 1 || $participantCount > 100 || mb_strlen($notes) > 1000) throw new \InvalidArgumentException('Invalid counter session participants.');
        $this->participantCount = $participantCount;
        $notes = trim($notes);
        $this->notes = $notes === '' ? null : $notes;
        $this->changed();
    }
    public function changed(): void { ++$this->revision; }
    public function end(): void
    {
        $this->endedAt ??= new \DateTimeImmutable();
        $this->activeCounterUuid = null;
        $this->changed();
    }
}
