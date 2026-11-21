<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;
use NsUltimate\Business\Accounting\Repository\JournalEntryRepository;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity(repositoryClass: JournalEntryRepository::class)]
#[ORM\Table(name: 'ns_accounting_journal', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
#[ORM\UniqueConstraint(name: 'uniq_acct_series', columns: ['book_id', 'series_year', 'sequence_no'])]
#[ORM\UniqueConstraint(name: 'uniq_acct_posting_index', columns: ['book_id', 'posting_index'])]
#[ORM\UniqueConstraint(name: 'uniq_acct_reversal', columns: ['book_id', 'reversal_of'])]
#[ORM\Index(name: 'idx_acct_journal_date', columns: ['book_id', 'accounting_date', 'sequence_no', 'id'])]
class JournalEntry
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: AccountingBook::class)]
    #[ORM\JoinColumn(name: 'book_id', nullable: false)]
    private AccountingBook $book;
    #[ORM\Id, ORM\Column(name: 'id', length: 36)]
    private string $uuid;
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $accountingDate;
    #[ORM\Column(length: 12)]
    private string $origin;
    #[ORM\Column(length: 12)]
    private string $state = 'draft';
    #[ORM\Column]
    private int $revision = 1;
    #[ORM\Column(length: 240)]
    private string $summary;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $evidence;
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $reversalOf = null;
    #[ORM\Column(nullable: true)]
    private ?int $seriesYear = null;
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $sequenceNo = null;
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $postingIndex = null;
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $postedAt = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $postedBy = null;

    private function __construct(AccountingBook $book, \DateTimeImmutable $date, string $origin, string $summary, ?string $evidence)
    {
        $this->book = $book;
        $this->uuid = UUID::v4();
        $this->accountingDate = $date;
        $this->origin = $origin;
        $this->summary = LedgerInput::text($summary, 240);
        $this->evidence = $evidence === null ? null : LedgerInput::text($evidence, 2000, true);
    }

    public static function draft(AccountingBook $book, \DateTimeImmutable $date, string $origin, string $summary, ?string $evidence): self
    {
        if (!in_array($origin, ['manual', 'opening'], true)) {
            throw new LedgerError('invalid_option');
        }

        return new self($book, $date, $origin, $summary, $evidence);
    }

    public function getBook(): AccountingBook { return $this->book; }
    public function getUuid(): string { return $this->uuid; }
    public function getAccountingDate(): \DateTimeImmutable { return $this->accountingDate; }
    public function getOrigin(): string { return $this->origin; }
    public function getState(): string { return $this->state; }
    public function getRevision(): int { return $this->revision; }
    public function getSummary(): string { return $this->summary; }
    #[Ignore]
    public function getEvidence(): ?string { return $this->evidence; }
    public function getReversalOf(): ?string { return $this->reversalOf; }
    public function getSeriesYear(): ?int { return $this->seriesYear; }
    public function getSequenceNo(): ?int { return $this->sequenceNo; }
    public function getPostingIndex(): ?int { return $this->postingIndex; }
    public function getPostedAt(): ?string { return $this->postedAt; }
    public function getPostedBy(): ?string { return $this->postedBy; }
    public function isOpening(): bool { return $this->origin === 'opening'; }
    public function __toString(): string { return $this->getNumber() ?? $this->uuid; }

    public function getNumber(): ?string
    {
        return $this->sequenceNo === null ? null : $this->seriesYear.'-'.$this->sequenceNo;
    }

    public function requireDraft(int $revision): void
    {
        if ($this->state !== 'draft' || $this->revision !== $revision) {
            throw new LedgerError('stale_draft', 409);
        }
    }

    public function updateDraft(int $revision, \DateTimeImmutable $date, string $summary, ?string $evidence): void
    {
        $this->requireDraft($revision);
        $summary = LedgerInput::text($summary, 240);
        $evidence = $evidence === null ? null : LedgerInput::text($evidence, 2000, true);
        $this->summary = $summary;
        $this->accountingDate = $date;
        $this->evidence = $evidence;
        ++$this->revision;
    }

    public function void(int $revision): void
    {
        $this->requireDraft($revision);
        $this->state = 'voided';
        ++$this->revision;
    }

    public function requireReversibleOn(\DateTimeImmutable $date): void
    {
        if ($this->state !== 'posted' || $this->origin !== 'manual' || $date->format('Y-m-d') < $this->accountingDate->format('Y-m-d')) {
            throw new LedgerError('journal_not_reversible', 409);
        }
    }

    public function reversal(\DateTimeImmutable $date, string $reason): self
    {
        $this->requireReversibleOn($date);
        $opposite = new self($this->book, $date, 'reversal', $this->summary, LedgerInput::text($reason, 1000));
        $opposite->reversalOf = $this->uuid;

        return $opposite;
    }

    /** @param list<JournalLine> $lines */
    public function markPosted(string $actor, int $sequence, int $postingIndex, \DateTimeImmutable $postedAt, array $lines): void
    {
        $this->requireDraft($this->revision);
        if ($this->isOpening() && ($this->evidence ?? '') === '') {
            throw new LedgerError('opening_evidence_required');
        }
        if ($sequence < 1 || $postingIndex < 1) {
            throw new \LogicException('A posting requires positive sequence numbers.');
        }
        $totals = ['debit' => 0, 'credit' => 0];
        foreach ($lines as $offset => $line) {
            if ($line->getJournalId() !== $this->uuid || $line->getBookId() !== $this->book->getId() || $line->getPosition() !== $offset + 1) {
                throw new LedgerError('invalid_lines');
            }
            $side = $line->getSide();
            $amount = $line->getAmount();
            if (PHP_INT_MAX - $totals[$side] < $amount) {
                throw new LedgerError('journal_amount_overflow');
            }
            $totals[$side] += $amount;
        }
        if (count($lines) < 2 || $totals['debit'] === 0 || $totals['debit'] !== $totals['credit']) {
            throw new LedgerError('unbalanced_journal');
        }
        $this->state = 'posted';
        ++$this->revision;
        $this->postedAt = $postedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $this->postedBy = $actor;
        $this->seriesYear = (int) $this->accountingDate->format('Y');
        $this->sequenceNo = $sequence;
        $this->postingIndex = $postingIndex;
    }
}
