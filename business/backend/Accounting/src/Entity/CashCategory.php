<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Core\Utils\UUID;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_cash_category', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
final class CashCategory
{
    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;
    public function __construct(
        #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
        #[ORM\JoinColumn(name: 'book_id', nullable: false, onDelete: 'CASCADE')]
        private AccountingBook $book,
        #[ORM\Id, ORM\Column(length: 24)]
        private string $category,
        #[ORM\Column(length: 7)]
        private string $direction,
        #[ORM\Column(length: 120)]
        private string $name,
    ) { $this->uuid = UUID::v4(); }

    public function getBook(): AccountingBook { return $this->book; }
    public function getUuid(): string { return $this->uuid; }
    public function getCategory(): string { return $this->category; }
    public function getDirection(): string { return $this->direction; }
    public function getName(): string { return $this->name; }
    public function change(string $direction, string $name): void { $this->direction = $direction; $this->name = $name; }
}
