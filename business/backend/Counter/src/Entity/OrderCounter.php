<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'counter_order')]
#[ORM\UniqueConstraint(name: 'uniq_counter_order_pair', columns: ['trade_order_uuid', 'counter_id'])]
#[ORM\Index(name: 'idx_counter_order_store', columns: ['store_uuid'])]
class OrderCounter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'trade_order_uuid', type: 'string', length: 36)]
    private string $tradeOrderUuid = '';

    #[ORM\ManyToOne(targetEntity: Counter::class)]
    #[ORM\JoinColumn(name: 'counter_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Counter $counter = null;

    #[ORM\ManyToOne(targetEntity: CounterSession::class)]
    #[ORM\JoinColumn(name: 'counter_session_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?CounterSession $counterSession = null;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(name: 'counter_code_snapshot', type: 'string', length: 50)]
    private string $counterCodeSnapshot = '';

    #[ORM\Column(name: 'counter_name_snapshot', type: 'string', length: 120)]
    private string $counterNameSnapshot = '';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->counterNameSnapshot !== '' ? $this->counterNameSnapshot : $this->counterCodeSnapshot;
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getTradeOrderUuid(): string { return $this->tradeOrderUuid; }
    public function setTradeOrderUuid(string $tradeOrderUuid): self { $this->tradeOrderUuid = $tradeOrderUuid; return $this; }
    public function getCounter(): ?Counter { return $this->counter; }
    public function setCounter(Counter $counter): self
    {
        if ($this->counter !== null) {
            if ($this->counter !== $counter) {
                throw new \LogicException('An order counter binding is immutable; delete it and create a replacement.');
            }
            return $this;
        }
        $this->counter = $counter;
        $this->counterCodeSnapshot = $counter->getCode();
        $this->counterNameSnapshot = $counter->getName();
        return $this;
    }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getCounterSession(): ?CounterSession { return $this->counterSession; }
    public function setCounterSession(CounterSession $session): self { $this->counterSession = $session; return $this; }
    public function setStoreUuid(string $storeUuid): self { $this->storeUuid = $storeUuid; return $this; }
    public function getCounterCodeSnapshot(): string { return $this->counterCodeSnapshot; }
    public function getCounterNameSnapshot(): string { return $this->counterNameSnapshot; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
