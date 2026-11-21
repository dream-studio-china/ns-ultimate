<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Tests;

use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\AccountingAuditRecord;
use NsUltimate\Business\Accounting\Entity\AccountingPeriod;
use NsUltimate\Business\Accounting\Entity\CommandReceipt;
use NsUltimate\Business\Accounting\Entity\JournalEntry;
use NsUltimate\Business\Accounting\Entity\JournalLine;
use NsUltimate\Business\Accounting\Entity\LedgerAccount;
use NsUltimate\Business\Accounting\Entity\PostingSeries;
use NsUltimate\Business\Accounting\Service\LedgerError;
use PHPUnit\Framework\TestCase;

final class LedgerEntityTest extends TestCase
{
    public function testJournalOwnsDraftRevisionAndPostingState(): void
    {
        $date = new \DateTimeImmutable('2026-01-01');
        $book = new AccountingBook('Test', 'CNY', 2, 'UTC', $date);
        $journal = JournalEntry::draft($book, $date, 'manual', 'Original', null);
        self::assertSame($journal->getUuid(), (string) $journal);
        $journal->updateDraft(1, $date, 'Edited', 'Evidence');
        $this->reject('stale_draft', fn () => $journal->void(1));
        $debit = new JournalLine($journal, 1, 'CASH', 'debit', 100);
        $credit = new JournalLine($journal, 2, 'REVENUE', 'credit', 100);
        $this->reject('unbalanced_journal', fn () => $journal->markPosted('actor', 1, 1, $date, [$debit]));
        self::assertSame(2, $journal->getRevision());
        self::assertNull($journal->getNumber());
        $journal->markPosted('actor', 1, 1, $date, [$debit, $credit]);
        self::assertSame('2026-1', $journal->getNumber());
        self::assertSame('2026-1', (string) $journal);
        self::assertSame($journal->getUuid().'#1', (string) $debit);
        self::assertSame(3, $journal->getRevision());
        $this->reject('stale_draft', fn () => $journal->updateDraft(3, $date, 'Forbidden', null));
        $this->reject('stale_draft', fn () => $journal->void(3));
        self::assertSame('Edited', $journal->getSummary());
        $reversal = $journal->reversal($date, 'Correction');
        $opposite = $debit->opposite($reversal);
        self::assertSame('credit', $opposite->getSide());
        self::assertSame(100, $opposite->getAmount());
        self::assertSame($journal->getUuid(), $reversal->getReversalOf());
    }

    public function testPeriodOpeningAndAccountLifecycle(): void
    {
        $book = new AccountingBook('Test', 'CNY', 2, 'UTC', new \DateTimeImmutable('2026-01-01'));
        $period = new AccountingPeriod($book, '2026-01');
        $period->lock();
        $this->reject('period_state_conflict', $period->lock(...));
        $period->reopen();
        self::assertTrue($period->isOpen());
        $this->reject('opening_state_conflict', fn () => $book->recordPosting(false));
        $book->approveZeroOpening();
        $this->reject('opening_already_established', $book->approveZeroOpening(...));
        self::assertSame(1, $book->recordPosting(false));

        $account = new LedgerAccount($book, 'CASH', 'Cash', 'asset', 'debit', true, true);
        $account->setName('Cash in bank')->setPostingAvailability(false, false);
        self::assertSame('Cash in bank', $account->getName());
        self::assertSame('CASH - Cash in bank', (string) $account);
        self::assertFalse($account->isPostable());
        $this->reject('account_has_posted_history', fn () => $account->reclassify('equity', 'credit', true));
        self::assertSame('asset', $account->getClassification());
    }

    public function testDisplayLabelsDoNotIncludeAuditOrReceiptPayloads(): void
    {
        $book = new AccountingBook('Example Company', 'CNY', 2, 'UTC', new \DateTimeImmutable('2026-01-01'));
        $audit = new AccountingAuditRecord($book, 'actor', 'post', 'target', null, ['reason' => 'Private note']);
        $receipt = new CommandReceipt($book, 'actor', 'post', 'command-id', 'fingerprint', ['private' => 'Private result']);

        self::assertSame('Example Company', (string) $book);
        self::assertSame('2026-01', (string) new AccountingPeriod($book, '2026-01'));
        self::assertSame('2026', (string) new PostingSeries($book, 2026));
        self::assertSame($audit->getId(), (string) $audit);
        self::assertSame('command-id', (string) $receipt);
    }

    private function reject(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Expected '.$code);
        } catch (LedgerError $error) {
            self::assertSame($code, $error->getMessage());
        }
    }
}
