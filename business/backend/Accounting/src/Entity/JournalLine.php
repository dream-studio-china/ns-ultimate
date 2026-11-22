<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_line', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
#[ORM\Index(name: 'idx_acct_account_lines', columns: ['book_id', 'account_code', 'journal_id'])]
class JournalLine
{
    #[ORM\Id, ORM\Column]
    private int $bookId;
    #[ORM\Id, ORM\Column(length: 36)]
    private string $journalId;
    #[ORM\Id, ORM\Column]
    private int $position;
    #[ORM\Column(length: 32)]
    private string $accountCode;
    #[ORM\Column(length: 6)]
    private string $side;
    #[ORM\Column(type: 'bigint')]
    private int $amount;
    #[ORM\Column(length: 240)]
    private string $description;

    public function __construct(JournalEntry $journal, int $position, string $accountCode, string $side, int $amount, string $description = '')
    {
        if ($position < 1 || $amount < 1 || !in_array($side, ['debit', 'credit'], true)) {
            throw new LedgerError('invalid_lines');
        }
        $this->bookId = $journal->getBook()->getId();
        $this->journalId = $journal->getUuid();
        $this->position = $position;
        $this->accountCode = LedgerInput::code($accountCode);
        $this->side = $side;
        $this->amount = $amount;
        $this->description = LedgerInput::text($description, 240, true);
    }

    public function getBookId(): int { return $this->bookId; }
    public function getJournalId(): string { return $this->journalId; }
    public function getPosition(): int { return $this->position; }
    public function getAccountCode(): string { return $this->accountCode; }
    public function getSide(): string { return $this->side; }
    public function getAmount(): int { return $this->amount; }
    public function getDescription(): string { return $this->description; }
    public function __toString(): string { return $this->journalId.'#'.$this->position; }

    public function opposite(JournalEntry $reversal): self
    {
        if ($reversal->getReversalOf() !== $this->journalId || $reversal->getBook()->getId() !== $this->bookId) {
            throw new \LogicException('The reversing line must point to its original journal.');
        }

        return new self($reversal, $this->position, $this->accountCode, $this->side === 'debit' ? 'credit' : 'debit', $this->amount, $this->description);
    }
}
