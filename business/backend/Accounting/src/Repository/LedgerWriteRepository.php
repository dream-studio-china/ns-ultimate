<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Repository;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\AccountingBookStore;
use NsUltimate\Business\Accounting\Entity\AccountingPeriod;
use NsUltimate\Business\Accounting\Entity\CashJournalMapping;
use NsUltimate\Business\Accounting\Entity\CashCategory;
use NsUltimate\Business\Accounting\Entity\CommandReceipt;
use NsUltimate\Business\Accounting\Entity\JournalEntry;
use NsUltimate\Business\Accounting\Entity\JournalLine;
use NsUltimate\Business\Accounting\Entity\LedgerAccount;
use NsUltimate\Business\Accounting\Entity\PostingSeries;
use NsUltimate\Business\Accounting\Entity\StoreCashEntry;
use NsUltimate\Business\Accounting\Service\LedgerError;

final class LedgerWriteRepository
{
    private ?EntityManagerInterface $work = null;

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function transactional(\Closure $operation): mixed
    {
        if ($this->work !== null) {
            throw new \LogicException('An Accounting transaction is already active.');
        }
        $db = $this->em->getConnection();
        // A fresh unit of work per attempt prevents stale entities or a failed
        // flush from leaking into a retry or closing the request's manager.
        $work = new EntityManager($db, $this->em->getConfiguration(), $this->em->getEventManager());
        $this->work = $work;
        try {
            return $db->transactional(static function () use ($operation, $work): mixed {
                $result = $operation();
                $work->flush();

                return $result;
            });
        } finally {
            $this->work = null;
            $work->close();
        }
    }

    public function add(object $record): void { $this->manager()->persist($record); }
    public function remove(object $record): void { $this->manager()->remove($record); }

    public function flush(): void { $this->manager()->flush(); }

    public function book(int $bookId): ?AccountingBook
    {
        return $this->manager()->find(AccountingBook::class, $bookId);
    }

    public function storeBinding(string $uuid): ?AccountingBookStore
    {
        return $this->manager()->find(AccountingBookStore::class, $uuid);
    }

    public function removeStoreBinding(AccountingBookStore $binding): void
    {
        $this->manager()->remove($binding);
    }

    public function hasStoreJournals(AccountingBook $book, string $uuid): bool
    {
        return (bool) $this->manager()->getConnection()->fetchOne(
            'SELECT 1 FROM ns_accounting_journal WHERE book_id = ? AND store_uuid = ? LIMIT 1',
            [$book->getId(), $uuid],
        );
    }

    /** @return list<string> */
    public function bookStores(AccountingBook $book): array
    {
        return array_map(static fn (AccountingBookStore $binding) => $binding->getStoreUuid(),
            $this->manager()->getRepository(AccountingBookStore::class)->findBy(['book' => $book], ['storeUuid' => 'ASC']));
    }

    public function lockBook(int $bookId): AccountingBook
    {
        // InnoDB takes the row lock; SQLite takes its writer lock before any read.
        $this->manager()->getConnection()->executeStatement(
            'UPDATE ns_accounting_book SET posting_count = posting_count WHERE id = ?', [$bookId],
        );

        return $this->book($bookId) ?? throw new LedgerError('book_not_initialized', 409);
    }

    public function account(AccountingBook $book, string $code): ?LedgerAccount
    {
        return $this->manager()->find(LedgerAccount::class, ['book' => $book, 'code' => $code]);
    }

    public function cashEntry(string $uuid): ?StoreCashEntry
    {
        return $this->manager()->find(StoreCashEntry::class, $uuid);
    }

    public function cashMapping(AccountingBook $book, string $category): ?CashJournalMapping
    {
        return $this->manager()->find(CashJournalMapping::class, ['book' => $book, 'category' => $category]);
    }

    public function cashCategoryDirection(AccountingBook $book, string $category): ?string
    {
        $direction = $this->manager()->getConnection()->fetchOne(
            'SELECT direction FROM ns_accounting_cash_category WHERE book_id = ? AND category = ?',
            [$book->getId(), $category],
        );

        return is_string($direction) ? $direction : null;
    }

    public function cashCategory(AccountingBook $book, string $category): ?CashCategory
    {
        return $this->manager()->find(CashCategory::class, ['book' => $book, 'category' => $category]);
    }

    public function cashCategoryInUse(AccountingBook $book, string $category): bool
    {
        return (bool) $this->manager()->getConnection()->fetchOne(
            'SELECT 1 FROM ns_store_cash_entry e JOIN ns_accounting_book_store b ON HEX(b.store_uuid) = HEX(e.store_uuid)
             WHERE b.book_id = ? AND e.category = ? UNION ALL
             SELECT 1 FROM ns_accounting_cash_mapping WHERE book_id = ? AND category = ? LIMIT 1',
            [$book->getId(), $category, $book->getId(), $category],
        );
    }

    public function journalForCash(AccountingBook $book, string $uuid): ?JournalEntry
    {
        return $this->manager()->createQuery(
            'SELECT j FROM '.JournalEntry::class.' j WHERE j.book = :book AND j.cashSourceUuid = :source',
        )->setParameter('book', $book)->setParameter('source', $uuid)->getOneOrNullResult();
    }

    public function accountHasPostedHistory(LedgerAccount $account): bool
    {
        return (bool) $this->manager()->getConnection()->fetchOne(
            "SELECT 1 FROM ns_accounting_line l JOIN ns_accounting_journal j ON j.book_id = l.book_id AND j.id = l.journal_id
             WHERE l.book_id = ? AND l.account_code = ? AND j.state = 'posted' LIMIT 1",
            [$account->getBook()->getId(), $account->getCode()],
        );
    }

    public function period(AccountingBook $book, string $month): ?AccountingPeriod
    {
        return $this->manager()->find(AccountingPeriod::class, ['book' => $book, 'month' => $month]);
    }

    public function journal(AccountingBook $book, string $id): ?JournalEntry
    {
        return $this->manager()->find(JournalEntry::class, ['book' => $book, 'uuid' => $id]);
    }

    public function reversal(JournalEntry $original): ?JournalEntry
    {
        return $this->manager()->createQuery(
            'SELECT j FROM '.JournalEntry::class.' j WHERE j.book = :book AND j.reversalOf = :original',
        )->setParameter('book', $original->getBook())->setParameter('original', $original->getUuid())->getOneOrNullResult();
    }

    public function series(AccountingBook $book, int $year): PostingSeries
    {
        $series = $this->manager()->find(PostingSeries::class, ['book' => $book, 'seriesYear' => $year]);
        if ($series === null) {
            $series = new PostingSeries($book, $year);
            $this->add($series);
        }

        return $series;
    }

    public function receipt(AccountingBook $book, string $actor, string $action, string $command): ?CommandReceipt
    {
        return $this->manager()->find(CommandReceipt::class, ['book' => $book, 'actor' => $actor, 'action' => $action, 'commandId' => $command]);
    }

    /** @return list<JournalLine> */
    public function lines(JournalEntry $journal): array
    {
        return array_values($this->manager()->getRepository(JournalLine::class)->findBy([
            'bookId' => $journal->getBook()->getId(), 'journalId' => $journal->getUuid(),
        ], ['position' => 'ASC']));
    }

    /** @param list<JournalLine> $lines */
    public function replaceDraftLines(JournalEntry $journal, array $lines): void
    {
        if ($journal->getState() !== 'draft') {
            throw new \LogicException('Only draft lines can be replaced.');
        }
        // Flush a newly created header before its scalar composite-key lines.
        // Replacing existing positions with the same PK requires deleting first.
        $manager = $this->manager();
        $manager->flush();
        $manager->getConnection()->delete('ns_accounting_line', [
            'book_id' => $journal->getBook()->getId(), 'journal_id' => $journal->getUuid(),
        ]);
        foreach ($lines as $line) {
            $this->add($line);
        }
    }

    private function manager(): EntityManagerInterface
    {
        return $this->work ?? throw new \LogicException('Accounting writes require a transaction.');
    }
}
