<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;
use NsUltimate\Business\Accounting\Service\LedgerError;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_series', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
class PostingSeries
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
    #[ORM\JoinColumn(name: 'book_id', nullable: false)]
    private AccountingBook $book;
    #[ORM\Id, ORM\Column]
    private int $seriesYear;
    #[ORM\Column(type: 'bigint')]
    private int $lastNo = 0;

    public function __construct(AccountingBook $book, int $seriesYear)
    {
        $this->book = $book;
        $this->seriesYear = $seriesYear;
    }

    public function getBook(): AccountingBook { return $this->book; }
    public function getSeriesYear(): int { return $this->seriesYear; }
    public function getLastNo(): int { return $this->lastNo; }
    public function __toString(): string { return (string) $this->seriesYear; }

    public function nextNumber(): int
    {
        if ($this->lastNo === PHP_INT_MAX) {
            throw new LedgerError('series_exhausted', 409);
        }

        return ++$this->lastNo;
    }
}
