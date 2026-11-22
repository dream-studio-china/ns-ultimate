<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Core\Utils\UUID;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_account', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
#[ORM\UniqueConstraint(name: 'uniq_accounting_account_uuid', columns: ['uuid'])]
class LedgerAccount
{
    #[ORM\Column(length: 36)]
    private string $uuid;
    #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
    #[ORM\JoinColumn(name: 'book_id', nullable: false)]
    private AccountingBook $book;
    #[ORM\Id, ORM\Column(length: 32)]
    private string $code;
    #[ORM\Column(length: 160)]
    private string $name;
    #[ORM\Column(length: 16)]
    private string $classification;
    #[ORM\Column(length: 6)]
    private string $normalSide;
    #[ORM\Column]
    private bool $active;
    #[ORM\Column]
    private bool $postable;

    public function __construct(AccountingBook $book, string $code, string $name, string $classification, string $normalSide, bool $active, bool $postable)
    {
        $this->uuid = UUID::v4();
        $this->book = $book;
        $this->code = LedgerInput::code($code);
        $this->setName($name);
        $this->setClassification($classification, $normalSide);
        $this->setPostingAvailability($active, $postable);
    }

    public function getBook(): AccountingBook { return $this->book; }
    public function getUuid(): string { return $this->uuid; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getClassification(): string { return $this->classification; }
    public function getNormalSide(): string { return $this->normalSide; }
    public function isActive(): bool { return $this->active; }
    public function isPostable(): bool { return $this->postable; }
    public function __toString(): string { return $this->code.' - '.$this->name; }

    public function setName(string $name): self
    {
        $this->name = LedgerInput::text($name, 160);

        return $this;
    }

    public function setPostingAvailability(bool $active, bool $postable): self
    {
        $this->active = $active;
        $this->postable = $postable;

        return $this;
    }

    public function reclassify(string $classification, string $normalSide, bool $hasPostedHistory): self
    {
        if ($hasPostedHistory && ($classification !== $this->classification || $normalSide !== $this->normalSide)) {
            throw new LedgerError('account_has_posted_history', 409);
        }
        $this->setClassification($classification, $normalSide);

        return $this;
    }

    private function setClassification(string $classification, string $normalSide): void
    {
        if (!in_array($classification, ['asset', 'liability', 'equity', 'revenue', 'expense'], true) || !in_array($normalSide, ['debit', 'credit'], true)) {
            throw new LedgerError('invalid_option');
        }
        $this->classification = $classification;
        $this->normalSide = $normalSide;
    }
}
