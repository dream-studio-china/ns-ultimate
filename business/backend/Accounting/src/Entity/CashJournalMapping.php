<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Core\Utils\UUID;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_cash_mapping', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
final class CashJournalMapping
{
    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;
    public function __construct(
        #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
        #[ORM\JoinColumn(name: 'book_id', nullable: false)]
        private AccountingBook $book,
        #[ORM\Id, ORM\Column(length: 24)]
        private string $category,
        #[ORM\Column(length: 32)]
        private string $debitCode,
        #[ORM\Column(length: 32)]
        private string $creditCode,
    ) { $this->uuid = UUID::v4(); }

    public function getBook(): AccountingBook { return $this->book; }
    public function getUuid(): string { return $this->uuid; }
    public function getCategory(): string { return $this->category; }
    public function getDebitCode(): string { return $this->debitCode; }
    public function getCreditCode(): string { return $this->creditCode; }

    public function changeAccounts(string $debit, string $credit): void
    {
        $this->debitCode = $debit;
        $this->creditCode = $credit;
    }
}
