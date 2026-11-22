<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_period', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
class AccountingPeriod
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
    #[ORM\JoinColumn(name: 'book_id', nullable: false)]
    private AccountingBook $book;
    #[ORM\Id, ORM\Column(length: 7)]
    private string $month;
    #[ORM\Column(length: 12)]
    private string $state = 'open';

    public function __construct(AccountingBook $book, string $month)
    {
        LedgerInput::date($month.'-01');
        if ($month < $book->getOpeningDate()->format('Y-m')) {
            throw new LedgerError('date_before_opening', 409);
        }
        $this->book = $book;
        $this->month = $month;
    }

    public function getBook(): AccountingBook { return $this->book; }
    public function getMonth(): string { return $this->month; }
    public function getState(): string { return $this->state; }
    public function isOpen(): bool { return $this->state === 'open'; }
    public function __toString(): string { return $this->month; }

    public function lock(): void
    {
        if (!$this->isOpen()) {
            throw new LedgerError('period_state_conflict', 409);
        }
        $this->state = 'locked';
    }

    public function reopen(): void
    {
        if ($this->state !== 'locked') {
            throw new LedgerError('period_state_conflict', 409);
        }
        $this->state = 'open';
    }
}
