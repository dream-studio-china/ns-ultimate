<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use NsUltimate\Business\Accounting\Entity\AccountingAuditRecord;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\AccountingBookStore;
use NsUltimate\Business\Accounting\Entity\AccountingPeriod;
use NsUltimate\Business\Accounting\Entity\CashJournalMapping;
use NsUltimate\Business\Accounting\Entity\CashCategory;
use NsUltimate\Business\Accounting\Entity\CommandReceipt;
use NsUltimate\Business\Accounting\Entity\JournalEntry;
use NsUltimate\Business\Accounting\Entity\JournalLine;
use NsUltimate\Business\Accounting\Entity\LedgerAccount;
use NsUltimate\Business\Accounting\Repository\LedgerWriteRepository;

final class LedgerCommands
{
    public function __construct(private readonly LedgerWriteRepository $storage) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(string $actor, string $action, array $input, int $bookId = AccountingBook::ID, ?string $storeUuid = null): array
    {
        $data = $this->normalize($action, $input);
        if ($action === 'initialize') {
            return $this->initialize($actor, $data, $bookId, $storeUuid);
        }
        if ($action === 'bind-store' || $action === 'create-cash-mapping') {
            try {
                return $this->storage->transactional(fn () => $action === 'bind-store'
                    ? $this->bindStore($this->storage->lockBook($bookId), $actor, $data['store_uuid'])
                    : $this->createCashMapping($this->storage->lockBook($bookId), $actor, $data));
            } catch (UniqueConstraintViolationException) {
                throw new LedgerError($action === 'bind-store' ? 'store_already_bound' : 'cash_mapping_exists', 409);
            }
        }
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->storage->transactional(fn () => $this->apply($actor, $action, $data, $bookId));
            } catch (RetryableException $e) {
                if ($attempt === 3) {
                    throw new LedgerError('temporarily_unavailable', 503);
                }
                usleep(10000 * $attempt);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    private function initialize(string $actor, array $data, int $bookId, ?string $storeUuid): array
    {
        $book = new AccountingBook($data['entity'], $data['currency'], $data['scale'], $data['timezone'], self::date($data['opening_date']), $bookId, $storeUuid);
        try {
            return $this->storage->transactional(function () use ($actor, $book, $bookId): array {
                $this->storage->add($book);
                if ($book->getStoreUuid() !== null) {
                    $this->storage->add(new AccountingBookStore($book->getStoreUuid(), $book));
                }
                $this->audit($book, $actor, 'initialize', (string) $bookId, null, ['opening_date' => $book->getOpeningDate()->format('Y-m-d')]);

                return ['id' => $bookId];
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->storage->transactional(fn () => $this->storage->book($bookId));
            if ($existing === null || $existing->getStoreUuid() !== $storeUuid || $existing->getEntity() !== $book->getEntity() || $existing->getCurrency() !== $book->getCurrency()
                || $existing->getScale() !== $book->getScale() || $existing->getTimezone() !== $book->getTimezone()
                || $existing->getOpeningDate()->format('Y-m-d') !== $book->getOpeningDate()->format('Y-m-d')) {
                throw new LedgerError('book_already_initialized', 409);
            }

            return ['id' => $bookId];
        }
    }

    /** @return array{store_uuid: string} */
    private function bindStore(AccountingBook $book, string $actor, string $uuid): array
    {
        $existing = $this->storage->storeBinding($uuid);
        if ($existing !== null && $existing->getBook()->getId() !== $book->getId()) {
            throw new LedgerError('store_already_bound', 409);
        }
        if ($existing === null) {
            $this->storage->add(new AccountingBookStore($uuid, $book));
            $this->audit($book, $actor, 'bind-store', $uuid, null, []);
        }

        return ['store_uuid' => $uuid];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function apply(string $actor, string $action, array $data, int $bookId): array
    {
        $book = $this->storage->lockBook($bookId);
        $command = $data['command_id'] ?? null;
        unset($data['command_id']);
        $fingerprintData = $data;
        if ($action === 'create-draft') {
            // Empty extensions must not change receipts made before they existed.
            if (!($data['attachments'] ?? [])) {
                unset($fingerprintData['attachments']);
            }
            if ((array) ($data['metadata'] ?? null) === []) {
                unset($fingerprintData['metadata']);
            }
        }
        $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
        if ($command !== null) {
            $receipt = $this->storage->receipt($book, $actor, $action, $command);
            if ($receipt) {
                if (!hash_equals($receipt->getFingerprint(), $fingerprint)) {
                    throw new LedgerError('command_key_conflict', 409);
                }

                return $receipt->getResult();
            }
        }

        $result = match ($action) {
            'unbind-store' => $this->unbindStore($book, $data['id']),
            'correct-cash-entry' => $this->correctCashEntry($book, $actor, $data, $command),
            'save-account', 'create-account', 'update-account' => $this->saveAccount($book, $action, $data),
            'add-period', 'lock-period', 'reopen-period' => $this->period($book, $action, $data),
            'approve-zero-opening' => $this->approveZero($book),
            'create-draft', 'update-draft' => $this->saveDraft($book, $data),
            'save-cash-mapping' => $this->saveCashMapping($book, $data),
            'delete-cash-mapping' => $this->deleteCashMapping($book, $data['category']),
            'create-cash-category', 'update-cash-category', 'delete-cash-category' => $this->cashCategory($book, $action, $data),
            'void-cash-entry' => $this->voidCashEntry($book, $actor, $data, $command),
            'draft-from-cash' => $this->draftFromCash($book, $data['source_uuid']),
            'void-draft' => $this->voidDraft($book, $data),
            'post' => $this->post($actor, $this->draft($book, $data)),
            'reverse' => $this->reverse($book, $actor, $data),
            default => throw new \LogicException('Unhandled normalized ledger command.'),
        };
        if (!in_array($action, ['correct-cash-entry', 'void-cash-entry'], true) && !($result['existing'] ?? false)) {
            $this->audit($book, $actor, $action, $result['id'], $command, array_intersect_key($data, array_flip(['reason', 'revision', 'active', 'postable', 'classification', 'normal_side', 'source_uuid', 'category', 'direction', 'name', 'debit_code', 'credit_code'])) + array_intersect_key($result, array_flip(['number'])));
        }
        if ($command !== null) {
            $this->storage->add(new CommandReceipt($book, $actor, $action, $command, $fingerprint, $result));
        }

        return $result;
    }

    /** @return array{id: string} */
    private function unbindStore(AccountingBook $book, string $uuid): array
    {
        $binding = $this->storage->storeBinding($uuid);
        if ($binding === null || $binding->getBook()->getId() !== $book->getId()) {
            throw new LedgerError('store_not_in_book', 409);
        }
        if ($this->storage->hasStoreJournals($book, $uuid)) {
            throw new LedgerError('store_has_journals', 409);
        }
        $this->storage->removeStoreBinding($binding);

        return ['id' => $uuid];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    private function correctCashEntry(AccountingBook $book, string $actor, array $data, ?string $command): array
    {
        $id = $data['id'];
        $entry = $this->storage->cashEntry($id);
        if ($entry === null || $this->storage->storeBinding($entry->getStoreUuid())?->getBook()->getId() !== $book->getId()) {
            throw new LedgerError('cash_entry_not_found', 404);
        }
        if ($entry->isVoided()) {
            throw new LedgerError('cash_entry_voided', 409);
        }
        if ($this->storage->journalForCash($book, $id) !== null) {
            throw new LedgerError('cash_entry_has_journal', 409);
        }
        $before = $entry->correctionSnapshot();
        if (!hash_equals(StoreCashMetadata::correctionVersion($before), $data['cash_version'])) {
            throw new LedgerError('stale_cash_entry', 409);
        }
        $category = $data['category'];
        $direction = $this->storage->cashCategoryDirection($book, $category)
            ?? throw new LedgerError('invalid_cash_category');
        $metadata = array_intersect_key($entry->getMetadata(), array_flip(['reference', 'extra']));
        if ($category === 'purchase') {
            $metadata += ['item' => $data['item'], 'quantity' => $data['quantity'], 'unit' => $data['unit']];
        }
        $entry->correct(self::date($data['date']), $category, $direction,
            (int) $data['amount'], $data['note'], StoreCashMetadata::normalize($category, $metadata));
        $this->audit($book, $actor, 'correct-cash-entry', $id, $command, [
            'reason' => $data['reason'], 'before' => $before, 'after' => $entry->correctionSnapshot(),
        ]);

        return ['id' => $id];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    private function voidCashEntry(AccountingBook $book, string $actor, array $data, ?string $command): array
    {
        $id = $data['id'];
        $entry = $this->storage->cashEntry($id);
        if ($entry === null || $this->storage->storeBinding($entry->getStoreUuid())?->getBook()->getId() !== $book->getId()) {
            throw new LedgerError('cash_entry_not_found', 404);
        }
        if ($entry->isVoided()) {
            throw new LedgerError('cash_entry_voided', 409);
        }
        $journal = $this->storage->journalForCash($book, $id);
        if ($journal !== null && $journal->getState() !== 'voided') {
            throw new LedgerError('cash_entry_has_journal', 409);
        }
        $before = $entry->correctionSnapshot();
        $entry->void($actor, $data['reason']);
        $this->audit($book, $actor, 'void-cash-entry', $id, $command, [
            'reason' => $data['reason'], 'before' => $before,
        ]);

        return ['id' => $id];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    private function saveCashMapping(AccountingBook $book, array $data): array
    {
        $category = $data['category'];
        if ($this->storage->cashCategory($book, $category) === null) {
            throw new LedgerError('cash_category_not_found', 404);
        }
        [$debit, $credit] = $this->cashMappingAccounts($book, $data);
        $mapping = $this->storage->cashMapping($book, $category);
        if ($mapping === null) {
            $mapping = new CashJournalMapping($book, $category, $debit, $credit);
            $this->storage->add($mapping);
        } else {
            $mapping->changeAccounts($debit, $credit);
        }

        return ['id' => $mapping->getCategory()];
    }

    /** @param array<string, mixed> $data
     * @return array{id: string}
     */
    private function createCashMapping(AccountingBook $book, string $actor, array $data): array
    {
        $category = $data['category'];
        if ($this->storage->cashCategory($book, $category) === null) {
            throw new LedgerError('cash_category_not_found', 404);
        }
        if ($this->storage->cashMapping($book, $category) !== null) {
            throw new LedgerError('cash_mapping_exists', 409);
        }
        [$debit, $credit] = $this->cashMappingAccounts($book, $data);
        $this->storage->add(new CashJournalMapping($book, $category, $debit, $credit));
        $this->audit($book, $actor, 'create-cash-mapping', $category, null, ['category' => $category, 'debit_code' => $debit, 'credit_code' => $credit]);

        return ['id' => $category];
    }

    /** @return array{id: string} */
    private function deleteCashMapping(AccountingBook $book, string $category): array
    {
        $mapping = $this->storage->cashMapping($book, $category)
            ?? throw new LedgerError('cash_mapping_not_found', 404);
        $this->storage->remove($mapping);

        return ['id' => $category];
    }

    /** @param array<string, mixed> $data
     * @return array{id: string}
     */
    private function cashCategory(AccountingBook $book, string $action, array $data): array
    {
        $category = $data['category'];
        $record = $this->storage->cashCategory($book, $category);
        if ($action === 'create-cash-category') {
            if ($record !== null) {
                throw new LedgerError('cash_category_exists', 409);
            }
            $this->storage->add(new CashCategory($book, $category, $data['direction'], $data['name']));
        } elseif ($action === 'update-cash-category') {
            $record ??= throw new LedgerError('cash_category_not_found', 404);
            if ($record->getDirection() !== $data['direction'] && $this->storage->cashCategoryInUse($book, $category)) {
                throw new LedgerError('cash_category_in_use', 409);
            }
            $record->change($data['direction'], $data['name']);
        } else {
            $record ??= throw new LedgerError('cash_category_not_found', 404);
            if ($this->storage->cashCategoryInUse($book, $category)) {
                throw new LedgerError('cash_category_in_use', 409);
            }
            $this->storage->remove($record);
        }

        return ['id' => $category];
    }

    /** @param array<string, mixed> $data
     * @return array{string, string} debit and credit codes
     */
    private function cashMappingAccounts(AccountingBook $book, array $data): array
    {
        $debit = $data['debit_code'];
        $credit = $data['credit_code'];
        if ($debit === $credit) {
            throw new LedgerError('cash_mapping_same_account');
        }
        foreach ([$debit, $credit] as $code) {
            $account = $this->storage->account($book, $code);
            if ($account === null || !$account->isActive() || !$account->isPostable()) {
                throw new LedgerError('account_not_postable', 409);
            }
        }

        return [$debit, $credit];
    }

    /** @return array{id: string, revision: int, existing: bool} */
    private function draftFromCash(AccountingBook $book, string $uuid): array
    {
        $entry = $this->storage->cashEntry($uuid);
        if ($entry === null || $this->storage->storeBinding($entry->getStoreUuid())?->getBook()->getId() !== $book->getId()) {
            throw new LedgerError('cash_entry_not_found', 404);
        }
        if ($entry->isVoided()) {
            throw new LedgerError('cash_entry_voided', 409);
        }
        $existing = $this->storage->journalForCash($book, $uuid);
        if ($existing !== null) {
            return ['id' => $existing->getUuid(), 'revision' => $existing->getRevision(), 'existing' => true];
        }
        $mapping = $this->storage->cashMapping($book, $entry->getCategory()) ?? throw new LedgerError('cash_mapping_required', 409);
        if ($entry->getCurrency() !== $book->getCurrency()) {
            throw new LedgerError('cash_currency_mismatch', 409);
        }
        // Store cash amounts always use two decimal places.
        $amount = (string) $entry->getAmount();
        $scale = $book->getScale();
        if ($scale < 2) {
            if ($entry->getAmount() % (10 ** (2 - $scale)) !== 0) {
                throw new LedgerError('cash_amount_scale_mismatch', 409);
            }
            $amount = (string) intdiv($entry->getAmount(), 10 ** (2 - $scale));
        } elseif ($scale > 2) {
            $amount .= str_repeat('0', $scale - 2);
        }
        $amount = LedgerInput::amount($amount);
        $result = $this->saveDraft($book, [
            'origin' => 'manual', 'summary' => 'Store cash: '.$entry->getCategory(), 'accounting_date' => $entry->getEntryDate()->format('Y-m-d'),
            'evidence' => '', 'attachments' => [], 'store_uuid' => $entry->getStoreUuid(),
            'lines' => [
                ['side' => 'debit', 'account_code' => $mapping->getDebitCode(), 'amount' => $amount, 'description' => ''],
                ['side' => 'credit', 'account_code' => $mapping->getCreditCode(), 'amount' => $amount, 'description' => ''],
            ],
        ], $uuid);

        return $result + ['existing' => false];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    private function saveAccount(AccountingBook $book, string $action, array $data): array
    {
        $code = $data['code'];
        $account = $this->storage->account($book, $code);
        if ($action === 'create-account' && $account !== null) {
            throw new LedgerError('account_already_exists', 409);
        }
        if ($action === 'update-account' && $account === null) {
            throw new LedgerError('account_not_found', 404);
        }
        if ($account === null) {
            $this->storage->add(new LedgerAccount($book, $code, $data['name'], $data['classification'], $data['normal_side'], (bool) $data['active'], (bool) $data['postable']));
        } else {
            $changedClass = $account->getClassification() !== $data['classification'] || $account->getNormalSide() !== $data['normal_side'];
            $account->reclassify($data['classification'], $data['normal_side'], $changedClass && $this->storage->accountHasPostedHistory($account))
                ->setName($data['name'])
                ->setPostingAvailability((bool) $data['active'], (bool) $data['postable']);
        }

        return ['id' => $code];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string}
     */
    private function period(AccountingBook $book, string $action, array $data): array
    {
        $month = $data['month'];
        if ($month < $book->getOpeningDate()->format('Y-m')) {
            throw new LedgerError('date_before_opening', 409);
        }
        $period = $this->storage->period($book, $month);
        if ($action === 'add-period') {
            if ($period !== null) {
                throw new LedgerError('period_already_exists', 409);
            }
            $this->storage->add(new AccountingPeriod($book, $month));
        } else {
            $period ??= throw new LedgerError('period_state_conflict', 409);
            $action === 'lock-period' ? $period->lock() : $period->reopen();
        }

        return ['id' => $month];
    }

    /**
     * @return array{id: string}
     */
    private function approveZero(AccountingBook $book): array
    {
        $book->approveZeroOpening();

        return ['id' => (string) $book->getId()];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string, revision: int}
     */
    private function saveDraft(AccountingBook $book, array $data, ?string $cashSourceUuid = null): array
    {
        $date = self::date($data['accounting_date']);
        if (isset($data['id'])) {
            $journal = $this->draft($book, $data);
            if (array_key_exists('store_uuid', $data)) {
                $journal->attributeDraft($data['revision'], $this->journalStore($book, $data['store_uuid']));
            }
            $journal->updateDraft(
                $data['revision'], $date, $data['summary'], $data['evidence'] ?? $journal->getEvidence(),
                $data['attachments'] ?? null, $data['metadata'] ?? null,
            );
        } else {
            $stores = $this->storage->bookStores($book);
            $uuid = array_key_exists('store_uuid', $data)
                ? $this->journalStore($book, $data['store_uuid'])
                : (count($stores) === 1 ? $stores[0] : null);
            $journal = JournalEntry::draft($book, $date, $data['origin'], $data['summary'], $data['evidence'], $data['attachments'], $data['metadata'] ?? null, $uuid, $cashSourceUuid);
            $this->storage->add($journal);
        }
        $this->checkDate($journal);
        $lines = [];
        foreach ($data['lines'] as $offset => $line) {
            $lines[] = new JournalLine($journal, $offset + 1, $line['account_code'], $line['side'], (int) $line['amount'], $line['description']);
        }
        $this->checkAccounts($book, $lines, false);
        $this->storage->replaceDraftLines($journal, $lines);

        return ['id' => $journal->getUuid(), 'revision' => $journal->getRevision()];
    }

    private function journalStore(AccountingBook $book, ?string $uuid): ?string
    {
        if ($uuid !== null && $this->storage->storeBinding($uuid)?->getBook()->getId() !== $book->getId()) {
            throw new LedgerError('store_not_in_book', 409);
        }

        return $uuid;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string, revision: int}
     */
    private function voidDraft(AccountingBook $book, array $data): array
    {
        $draft = $this->journal($book, $data['id']);
        $draft->void($data['revision']);

        return ['id' => $draft->getUuid(), 'revision' => $draft->getRevision()];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string, number: string}
     */
    private function reverse(AccountingBook $book, string $actor, array $data): array
    {
        $original = $this->journal($book, $data['id']);
        $date = self::date($data['accounting_date']);
        $original->requireReversibleOn($date);
        $previous = $this->storage->reversal($original);
        if ($previous) {
            if ($previous->getAccountingDate()->format('Y-m-d') !== $data['accounting_date'] || $previous->getEvidence() !== $data['reason']) {
                throw new LedgerError('reversal_already_exists', 409);
            }

            return $this->postingResult($previous);
        }
        $reversal = $original->reversal($date, $data['reason']);
        $this->storage->add($reversal);
        $lines = array_map(static fn (JournalLine $line) => $line->opposite($reversal), $this->storage->lines($original));
        $this->storage->replaceDraftLines($reversal, $lines);

        return $this->post($actor, $reversal, $lines);
    }

    /**
     * @param list<JournalLine>|null $lines
     * @return array{id: string, number: string}
     */
    private function post(string $actor, JournalEntry $journal, ?array $lines = null): array
    {
        $book = $journal->getBook();
        $this->checkDate($journal);
        $book->assertCanPost($journal->isOpening());
        $lines ??= $this->storage->lines($journal);
        $this->checkAccounts($book, $lines, $journal->getReversalOf() !== null);
        $sequence = $this->storage->series($book, (int) $journal->getAccountingDate()->format('Y'))->nextNumber();
        $index = $book->recordPosting($journal->isOpening());
        $journal->markPosted($actor, $sequence, $index, new \DateTimeImmutable(), $lines);
        // Write the posting before its audit receipt, but keep both in the outer transaction.
        $this->storage->flush();

        return $this->postingResult($journal);
    }

    /** @return array{id: string, number: string} */
    private function postingResult(JournalEntry $journal): array
    {
        return ['id' => $journal->getUuid(), 'number' => $journal->getNumber() ?? throw new \LogicException('A posted journal has no number.')];
    }

    private function checkDate(JournalEntry $journal): void
    {
        $book = $journal->getBook();
        $date = $journal->getAccountingDate()->format('Y-m-d');
        $opening = $book->getOpeningDate()->format('Y-m-d');
        if ($date < $opening || ($journal->isOpening() && $date !== $opening)) {
            throw new LedgerError('invalid_accounting_date', 409);
        }
        if (!$this->storage->period($book, substr($date, 0, 7))?->isOpen()) {
            throw new LedgerError('period_not_open', 409);
        }
    }

    /** @param list<JournalLine> $lines */
    private function checkAccounts(AccountingBook $book, array $lines, bool $historical): void
    {
        foreach (array_unique(array_map(static fn (JournalLine $line) => $line->getAccountCode(), $lines)) as $code) {
            $account = $this->storage->account($book, $code);
            if ($account === null || (!$historical && (!$account->isActive() || !$account->isPostable()))) {
                throw new LedgerError('account_not_postable', 409);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function draft(AccountingBook $book, array $data): JournalEntry
    {
        $journal = $this->journal($book, $data['id']);
        $journal->requireDraft($data['revision']);

        return $journal;
    }

    private function journal(AccountingBook $book, string $id): JournalEntry
    {
        return $this->storage->journal($book, $id)
            ?? throw new LedgerError('journal_not_found', 404);
    }

    /** @param array<string, mixed> $detail */
    private function audit(AccountingBook $book, string $actor, string $action, string $target, ?string $correlation, array $detail): void
    {
        $this->storage->add(new AccountingAuditRecord($book, $actor, $action, $target, $correlation, $detail));
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(string $action, array $data): array
    {
        $fields = match ($action) {
            'initialize' => ['entity', 'currency', 'scale', 'timezone', 'opening_date'],
            'save-account', 'create-account', 'update-account' => ['code', 'name', 'classification', 'normal_side', 'active', 'postable'],
            'add-period' => ['month'],
            'lock-period', 'reopen-period' => ['month', 'reason', 'command_id'],
            'approve-zero-opening' => ['reason', 'command_id'],
            'bind-store' => ['store_uuid'],
            'unbind-store' => ['id', 'reason', 'command_id'],
            'correct-cash-entry' => ['id', 'cash_version', 'date', 'category', 'amount', 'note', 'item', 'quantity', 'unit', 'reason', 'command_id'],
            'void-cash-entry' => ['id', 'reason', 'command_id'],
            'save-cash-mapping', 'create-cash-mapping' => ['category', 'debit_code', 'credit_code'],
            'delete-cash-mapping' => ['category'],
            'create-cash-category' => ['category', 'direction', 'name'],
            'update-cash-category' => ['category', 'direction', 'name'],
            'delete-cash-category' => ['category'],
            'draft-from-cash' => ['source_uuid'],
            'create-draft' => ['origin', 'accounting_date', 'summary', 'lines', 'evidence', 'attachments', 'metadata', 'store_uuid', 'command_id'],
            'update-draft' => ['id', 'revision', 'accounting_date', 'summary', 'lines', 'evidence', 'attachments', 'metadata', 'store_uuid'],
            'void-draft' => ['id', 'revision', 'reason', 'command_id'],
            'post' => ['id', 'revision', 'command_id'],
            'reverse' => ['id', 'accounting_date', 'reason', 'command_id'],
            default => throw new LedgerError('unknown_command', 404),
        };
        LedgerInput::fields($data, ...$fields);
        $normalized = [];
        foreach ($fields as $key) {
            if ($key === 'store_uuid' && $action !== 'bind-store' && !array_key_exists($key, $data)) {
                continue;
            }
            if (!array_key_exists($key, $data) && ($key === 'metadata'
                || ($action === 'update-draft' && in_array($key, ['evidence', 'attachments'], true)))) {
                if ($key === 'metadata' && $action === 'create-draft') {
                    $normalized[$key] = new \stdClass();
                }
                continue;
            }
            $value = $data[$key] ?? null;
            $normalized[$key] = match ($key) {
                'id', 'command_id', 'source_uuid' => LedgerInput::uuid($value),
                'store_uuid' => $action !== 'bind-store' && ($value === null || $value === '') ? null : LedgerInput::uuid($value),
                'revision' => LedgerInput::revision($value),
                'opening_date', 'accounting_date', 'date' => LedgerInput::date($value),
                'cash_version' => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) ? $value : throw new LedgerError('stale_cash_entry', 409),
                'amount' => LedgerInput::amount($value),
                'note' => LedgerInput::text($value ?? '', 500, true),
                'item', 'quantity', 'unit' => $value,
                'month' => substr(LedgerInput::date(is_string($value) ? $value.'-01' : null), 0, 7),
                'currency' => is_string($value) && preg_match('/\A[A-Z]{3}\z/', $value) ? $value : throw new LedgerError('invalid_currency'),
                'scale' => is_int($value) && $value >= 0 && $value <= 6 ? $value : throw new LedgerError('invalid_scale'),
                'timezone' => is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : throw new LedgerError('invalid_timezone'),
                'entity' => LedgerInput::text($value, 160),
                'name' => LedgerInput::text($value, $action === 'create-cash-category' || $action === 'update-cash-category' ? 120 : 160),
                'summary' => LedgerInput::text($value, 240),
                'reason' => LedgerInput::text($value, 1000),
                'evidence' => LedgerInput::text($value ?? '', 2000, true),
                'code', 'debit_code', 'credit_code' => LedgerInput::code($value),
                'category' => is_string($value) && preg_match('/\A[a-z][a-z0-9_]{0,23}\z/', $value) ? $value : throw new LedgerError('invalid_option'),
                'direction' => self::choice($value, 'income', 'expense'),
                'origin' => self::choice($value, 'manual', 'opening'),
                'classification' => self::choice($value, 'asset', 'liability', 'equity', 'revenue', 'expense'),
                'normal_side' => self::choice($value, 'debit', 'credit'),
                'active', 'postable' => is_bool($value) ? (int) $value : throw new LedgerError('invalid_account_flag'),
                'lines' => $this->normalizeLines($value),
                'attachments' => $this->normalizeAttachments($value ?? []),
                'metadata' => LedgerInput::metadata($value),
            };
        }

        return $normalized;
    }

    /** @return list<string> */
    private function normalizeAttachments(mixed $attachments): array
    {
        if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 20) {
            throw new LedgerError('invalid_attachments');
        }
        return array_map(static function (mixed $url): string {
            $url = LedgerInput::text($url, 2048);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
                throw new LedgerError('invalid_attachment');
            }
            return $url;
        }, $attachments);
    }

    /** @return list<array{account_code: string, side: string, amount: string, description: string}> */
    private function normalizeLines(mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > 100) {
            throw new LedgerError('invalid_lines');
        }

        return array_map(static function (mixed $line): array {
            if (!is_array($line) || array_is_list($line)) {
                throw new LedgerError('invalid_lines');
            }
            LedgerInput::fields($line, 'account_code', 'side', 'amount', 'description');
            return [
                'account_code' => LedgerInput::code($line['account_code'] ?? null),
                'side' => self::choice($line['side'] ?? null, 'debit', 'credit'),
                'amount' => LedgerInput::amount($line['amount'] ?? null),
                'description' => LedgerInput::text($line['description'] ?? '', 240, true),
            ];
        }, $lines);
    }

    private static function choice(mixed $value, string ...$choices): string
    {
        return in_array($value, $choices, true) ? $value : throw new LedgerError('invalid_option');
    }
}
