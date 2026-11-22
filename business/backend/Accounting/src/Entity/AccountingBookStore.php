<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_book_store', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
#[ORM\Index(name: 'idx_acct_book_store_book', columns: ['book_id'])]
final class AccountingBookStore
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 36)]
        private string $storeUuid,
        #[ORM\ManyToOne(targetEntity: AccountingBook::class)]
        #[ORM\JoinColumn(name: 'book_id', nullable: false)]
        private AccountingBook $book,
    ) {}

    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getBook(): AccountingBook { return $this->book; }
}
