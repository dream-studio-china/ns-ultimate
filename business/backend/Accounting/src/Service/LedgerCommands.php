<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use NsUltimate\Business\Accounting\Entity\AccountingAuditRecord;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\AccountingPeriod;
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
            'save-account', 'create-account', 'update-account' => $this->saveAccount($book, $action, $data),
            'add-period', 'lock-period', 'reopen-period' => $this->period($book, $action, $data),
            'approve-zero-opening' => $this->approveZero($book),
            'create-draft', 'update-draft' => $this->saveDraft($book, $data),
            'void-draft' => $this->voidDraft($book, $data),
            'post' => $this->post($actor, $this->draft($book, $data)),
            'reverse' => $this->reverse($book, $actor, $data),
            default => throw new \LogicException('Unhandled normalized ledger command.'),
        };
        $this->audit($book, $actor, $action, $result['id'], $command, array_intersect_key($data, array_flip(['reason', 'revision', 'active', 'postable', 'classification', 'normal_side'])) + array_intersect_key($result, array_flip(['number'])));
        if ($command !== null) {
            $this->storage->add(new CommandReceipt($book, $actor, $action, $command, $fingerprint, $result));
        }

        return $result;
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
    private function saveDraft(AccountingBook $book, array $data): array
    {
        $date = self::date($data['accounting_date']);
        if (isset($data['id'])) {
            $journal = $this->draft($book, $data);
            $journal->updateDraft(
                $data['revision'], $date, $data['summary'], $data['evidence'] ?? $journal->getEvidence(),
                $data['attachments'] ?? null, $data['metadata'] ?? null,
            );
        } else {
            $journal = JournalEntry::draft($book, $date, $data['origin'], $data['summary'], $data['evidence'], $data['attachments'], $data['metadata'] ?? null);
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
            'create-draft' => ['origin', 'accounting_date', 'summary', 'lines', 'evidence', 'attachments', 'metadata', 'command_id'],
            'update-draft' => ['id', 'revision', 'accounting_date', 'summary', 'lines', 'evidence', 'attachments', 'metadata'],
            'void-draft' => ['id', 'revision', 'reason', 'command_id'],
            'post' => ['id', 'revision', 'command_id'],
            'reverse' => ['id', 'accounting_date', 'reason', 'command_id'],
            default => throw new LedgerError('unknown_command', 404),
        };
        LedgerInput::fields($data, ...$fields);
        $normalized = [];
        foreach ($fields as $key) {
            if (!array_key_exists($key, $data) && ($key === 'metadata'
                || ($action === 'update-draft' && in_array($key, ['evidence', 'attachments'], true)))) {
                if ($key === 'metadata' && $action === 'create-draft') {
                    $normalized[$key] = new \stdClass();
                }
                continue;
            }
            $value = $data[$key] ?? null;
            $normalized[$key] = match ($key) {
                'id', 'command_id' => LedgerInput::uuid($value),
                'revision' => LedgerInput::revision($value),
                'opening_date', 'accounting_date' => LedgerInput::date($value),
                'month' => substr(LedgerInput::date(is_string($value) ? $value.'-01' : null), 0, 7),
                'currency' => is_string($value) && preg_match('/\A[A-Z]{3}\z/', $value) ? $value : throw new LedgerError('invalid_currency'),
                'scale' => is_int($value) && $value >= 0 && $value <= 6 ? $value : throw new LedgerError('invalid_scale'),
                'timezone' => is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : throw new LedgerError('invalid_timezone'),
                'entity', 'name' => LedgerInput::text($value, 160),
                'summary' => LedgerInput::text($value, 240),
                'reason' => LedgerInput::text($value, 1000),
                'evidence' => LedgerInput::text($value ?? '', 2000, true),
                'code' => LedgerInput::code($value),
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
