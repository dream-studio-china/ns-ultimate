<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'ns_accounting_receipt', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
class CommandReceipt
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
    #[ORM\JoinColumn(name: 'book_id', nullable: false)]
    private AccountingBook $book;
    #[ORM\Id, ORM\Column(length: 64)]
    private string $actor;
    #[ORM\Id, ORM\Column(name: '`action`', length: 32)]
    private string $action;
    #[ORM\Id, ORM\Column(length: 36)]
    private string $commandId;
    #[ORM\Column(length: 64)]
    private string $fingerprint;
    #[ORM\Column(type: 'text')]
    private string $result;

    /** @param array<string, mixed> $result */
    public function __construct(AccountingBook $book, string $actor, string $action, string $commandId, string $fingerprint, array $result)
    {
        $this->book = $book;
        $this->actor = $actor;
        $this->action = $action;
        $this->commandId = $commandId;
        $this->fingerprint = $fingerprint;
        $this->result = json_encode($result, JSON_THROW_ON_ERROR);
    }

    public function getBook(): AccountingBook { return $this->book; }
    public function getActor(): string { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getCommandId(): string { return $this->commandId; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function __toString(): string { return $this->commandId; }

    /** @return array<string, mixed> */
    public function getResult(): array
    {
        return json_decode($this->result, true, flags: JSON_THROW_ON_ERROR);
    }
}
