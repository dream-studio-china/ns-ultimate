<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'ns_accounting_audit', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
#[ORM\Index(name: 'idx_acct_audit_time', columns: ['book_id', 'occurred_at', 'id'])]
class AccountingAuditRecord
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
    #[ORM\JoinColumn(name: 'book_id', nullable: false)]
    private AccountingBook $book;
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(length: 64)]
    private string $actor;
    #[ORM\Column(name: '`action`', length: 32)]
    private string $action;
    #[ORM\Column(length: 64)]
    private string $target;
    #[ORM\Column(length: 32)]
    private string $occurredAt;
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $correlationId;
    #[ORM\Column(type: 'text')]
    private string $detail;

    /** @param array<string, mixed> $detail */
    public function __construct(AccountingBook $book, string $actor, string $action, string $target, ?string $correlationId, array $detail)
    {
        $this->book = $book;
        $this->id = UUID::v4();
        $this->actor = $actor;
        $this->action = $action;
        $this->target = $target;
        $this->occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $this->correlationId = $correlationId;
        $this->detail = json_encode($detail, JSON_THROW_ON_ERROR);
    }

    public function getBook(): AccountingBook { return $this->book; }
    public function getId(): string { return $this->id; }
    public function getActor(): string { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getTarget(): string { return $this->target; }
    public function getOccurredAt(): string { return $this->occurredAt; }
    public function getCorrelationId(): ?string { return $this->correlationId; }
    public function getDetail(): string { return $this->detail; }
    public function __toString(): string { return $this->id; }
}
