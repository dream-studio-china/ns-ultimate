<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Repository;

use Brick\Math\BigInteger;
use Doctrine\DBAL\Connection;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\LedgerPage;

final class LedgerReadRepository
{
    private const MAX_EXPORT = 10000;
    private const FROM = ' FROM ns_accounting_line l JOIN ns_accounting_journal j ON j.book_id = l.book_id AND j.id = l.journal_id
                           WHERE l.book_id = ? AND j.state = \'posted\' AND j.posting_index <= CAST(? AS DECIMAL(19, 0))';

    public function __construct(private readonly Connection $db) {}

    /** @return array<string, mixed> */
    public function journal(string $id, bool $evidence, int $bookId = AccountingBook::ID): array
    {
        $id = LedgerInput::uuid($id);

        return $this->db->transactional(function () use ($id, $evidence, $bookId): array {
            // Fetch header and lines in one statement so a draft update cannot split the view.
            $rows = $this->db->fetchAllAssociative(
                "SELECT j.*, r.id AS reversed_by, l.account_code, l.side, l.amount, l.description, l.position FROM ns_accounting_journal j
                 LEFT JOIN ns_accounting_line l ON l.book_id = j.book_id AND l.journal_id = j.id
                 LEFT JOIN ns_accounting_journal r ON r.book_id = j.book_id AND r.reversal_of = j.id AND r.state = 'posted'
                 WHERE j.book_id = ? AND j.id = ? ORDER BY l.position", [$bookId, $id],
            );
            if (!$rows) {
                throw new LedgerError('journal_not_found', 404);
            }
            $lineKeys = array_flip(['account_code', 'side', 'amount', 'description', 'position']);
            $journal = array_diff_key($rows[0], $lineKeys, array_flip(['posting_index', 'book_id']));
            $journal['revision'] = (int) $journal['revision'];
            if ($evidence) {
                $journal['attachments'] = json_decode((string) ($journal['attachments'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
                $journal['metadata'] = json_decode((string) ($journal['metadata'] ?? '{}'), flags: JSON_THROW_ON_ERROR);
            } else {
                unset($journal['evidence'], $journal['attachments'], $journal['metadata']);
            }
            $journal['lines'] = [];
            foreach ($rows as $row) {
                if ($row['position'] !== null) {
                    $row['amount'] = (string) $row['amount'];
                    $journal['lines'][] = array_intersect_key($row, $lineKeys);
                }
            }

            return $this->journalNumber($journal);
        });
    }

    /** @param array<string, mixed> $parameters */
    public function audit(array $parameters, int $bookId = AccountingBook::ID): LedgerPage
    {
        LedgerInput::fields($parameters, 'page', 'limit');
        [$page, $limit] = LedgerPage::window($parameters);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, actor, action, target, occurred_at, correlation_id, detail FROM ns_accounting_audit WHERE book_id = ? ORDER BY occurred_at DESC, id DESC'
            .' LIMIT '.$limit.' OFFSET '.(($page - 1) * $limit), [$bookId],
        );
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ns_accounting_audit WHERE book_id = ?', [$bookId]);

        return new LedgerPage(array_map($this->auditRow(...), $rows), $total, $page, $limit);
    }

    /** @return array<string, mixed> */
    public function auditRecord(string $id, int $bookId = AccountingBook::ID): array
    {
        $record = $this->db->fetchAssociative(
            'SELECT id, actor, action, target, occurred_at, correlation_id, detail FROM ns_accounting_audit WHERE book_id = ? AND id = ?',
            [$bookId, LedgerInput::uuid($id)],
        );

        return $this->auditRow($record ?: throw new LedgerError('audit_record_not_found', 404));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function auditRow(array $row): array
    {
        $row['detail'] = json_decode((string) $row['detail'], true, flags: JSON_THROW_ON_ERROR);

        return $row;
    }

    /**
     * Read one report page, or every row for export, at a posting cutoff.
     *
     * The first request captures the book's current posting count as the
     * cutoff. Later pages and the CSV export pass the same cutoff and filters so
     * they read the same posted snapshot.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function report(string $kind, array $parameters, bool $export = false, int $bookId = AccountingBook::ID): array
    {
        if (!in_array($kind, ['ledger', 'balances', 'trial'], true)) {
            throw new LedgerError('unknown_report', 404);
        }
        LedgerInput::fields($parameters, 'from', 'to', 'account', 'cutoff', ...($export ? [] : ['page', 'limit']));
        if ($kind === 'trial' && isset($parameters['account'])) {
            throw new LedgerError('trial_requires_whole_book');
        }
        $from = LedgerInput::date($parameters['from'] ?? null);
        $to = LedgerInput::date($parameters['to'] ?? null);
        if ($from > $to) {
            throw new LedgerError('invalid_range');
        }
        $account = isset($parameters['account']) ? LedgerInput::code($parameters['account']) : null;
        if ($kind === 'ledger' && $account === null) {
            throw new LedgerError('account_required');
        }
        [$page, $limit] = LedgerPage::window($parameters);
        return $this->db->transactional(function () use ($kind, $parameters, $export, $from, $to, $account, $page, $limit, $bookId): array {
            $book = $this->db->fetchAssociative('SELECT * FROM ns_accounting_book WHERE id = ?', [$bookId])
                ?: throw new LedgerError('book_not_initialized', 409);
            $cutoff = $parameters['cutoff'] ?? (string) $book['posting_count'];
            if ($cutoff !== '0') {
                LedgerInput::amount($cutoff);
            }
            if (BigInteger::of($cutoff)->compareTo((string) $book['posting_count']) > 0) {
                throw new LedgerError('invalid_cutoff');
            }
            $result = $kind === 'ledger'
                ? $this->ledger($bookId, $account ?? throw new \LogicException('Missing account.'), $from, $to, $cutoff, $page, $limit, $export)
                : $this->balances($bookId, $account, $from, $to, $cutoff, $page, $limit, $export, $kind === 'trial');

            return $result + ['cutoff' => $cutoff, 'currency' => $book['currency'], 'scale' => (int) $book['scale']];
        });
    }

    /** @return array<string, mixed> */
    private function ledger(int $bookId, string $account, string $from, string $to, string $cutoff, int $page, int $limit, bool $export): array
    {
        $this->requireAccount($bookId, $account);
        $offset = ($page - 1) * $limit;
        $base = self::FROM.' AND l.account_code = ?';
        $opening = BigInteger::zero();
        foreach ($this->db->iterateAssociative('SELECT l.side, l.amount'.$base.' AND j.accounting_date < ?', [$bookId, $cutoff, $account, $from]) as $line) {
            $opening = $this->apply($opening, $line);
        }
        $balance = $opening;
        $rows = [];
        $index = 0;
        $range = $base.' AND j.accounting_date >= ? AND j.accounting_date <= ?';
        $arguments = [$bookId, $cutoff, $account, $from, $to];
        $sql = 'SELECT j.id, j.accounting_date, j.series_year, j.sequence_no, j.summary, l.position, l.side, l.amount, l.description'.$range
            .' ORDER BY j.accounting_date, j.sequence_no, j.id, l.position';
        // Running balances need every earlier line in the range, so read up to the end of the page.
        $end = $export ? self::MAX_EXPORT + 1 : $offset + $limit;
        foreach ($this->db->iterateAssociative($sql.' LIMIT '.$end, $arguments) as $line) {
            $balance = $this->apply($balance, $line);
            if ($index++ >= $offset) {
                $line['amount'] = (string) $line['amount'];
                $rows[] = $this->journalNumber($line) + ['balance' => (string) $balance];
            }
        }
        if ($export) {
            return $this->export($rows) + ['opening' => (string) $opening];
        }
        $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$range, $arguments);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'opening' => (string) $opening];
    }

    /** @return array<string, mixed> */
    private function balances(int $bookId, ?string $account, string $from, string $to, string $cutoff, int $page, int $limit, bool $export, bool $trial): array
    {
        $zero = BigInteger::zero();
        $args = [$bookId, $cutoff, $to];
        $sql = 'SELECT l.account_code, l.side, l.amount, j.accounting_date'.self::FROM.' AND j.accounting_date <= ?';
        if ($account !== null) {
            $this->requireAccount($bookId, $account);
            $sql .= ' AND l.account_code = ?';
            $args[] = $account;
        }
        $totals = [];
        foreach ($this->db->iterateAssociative($sql, $args) as $line) {
            $key = $line['account_code'];
            $bucket = $line['accounting_date'] < $from ? 'opening_'.$line['side'] : 'movement_'.$line['side'];
            $totals[$key][$bucket] = ($totals[$key][$bucket] ?? $zero)->plus((string) $line['amount']);
        }
        $grand = [];
        $rows = [];
        $accounts = $this->db->fetchAllAssociative('SELECT code, name FROM ns_accounting_account WHERE book_id = ?'.($account === null ? '' : ' AND code = ?').' ORDER BY code', $account === null ? [$bookId] : [$bookId, $account]);
        foreach ($accounts as $entry) {
            // Without an explicit account filter, only show accounts with activity
            // by the selected cutoff and end date. New account rows cannot shift
            // the financial report's pagination.
            if ($account === null && !isset($totals[$entry['code']])) {
                continue;
            }
            $entryTotals = $totals[$entry['code']] ?? [];
            $opening = ($entryTotals['opening_debit'] ?? $zero)->minus($entryTotals['opening_credit'] ?? $zero);
            $debit = $entryTotals['movement_debit'] ?? $zero;
            $credit = $entryTotals['movement_credit'] ?? $zero;
            $closing = $opening->plus($debit)->minus($credit);
            $values = [
                'opening_debit' => $opening->isPositive() ? $opening : $zero,
                'opening_credit' => $opening->isNegative() ? $opening->negated() : $zero,
                'movement_debit' => $debit, 'movement_credit' => $credit,
                'closing_debit' => $closing->isPositive() ? $closing : $zero,
                'closing_credit' => $closing->isNegative() ? $closing->negated() : $zero,
            ];
            foreach ($values as $key => $value) {
                $grand[$key] = ($grand[$key] ?? $zero)->plus($value);
                $entry[$key] = (string) $value;
            }
            $rows[] = $entry;
        }
        if ($export) {
            $result = $this->export($rows);
        } else {
            $result = ['rows' => array_slice($rows, ($page - 1) * $limit, $limit), 'total' => count($rows), 'page' => $page, 'limit' => $limit];
        }

        return $trial ? $result + ['totals' => array_map('strval', $grand ?: array_fill_keys(['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'closing_debit', 'closing_credit'], $zero))] : $result;
    }

    /** @param array<string, mixed> $line */
    private function apply(BigInteger $balance, array $line): BigInteger
    {
        return $line['side'] === 'debit' ? $balance->plus((string) $line['amount']) : $balance->minus((string) $line['amount']);
    }

    private function requireAccount(int $bookId, string $account): void
    {
        if (!$this->db->fetchOne('SELECT 1 FROM ns_accounting_account WHERE book_id = ? AND code = ?', [$bookId, $account])) {
            throw new LedgerError('account_not_found', 404);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function journalNumber(array $row): array
    {
        $row['number'] = $row['sequence_no'] === null ? null : $row['series_year'].'-'.$row['sequence_no'];
        unset($row['series_year'], $row['sequence_no']);

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>}
     */
    private function export(array $rows): array
    {
        if (count($rows) > self::MAX_EXPORT) {
            throw new LedgerError('export_too_large', 422);
        }

        return ['rows' => $rows];
    }
}
