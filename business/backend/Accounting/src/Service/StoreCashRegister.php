<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use App\Store\Entity\Store;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Accounting\Entity\StoreCashEntry;
use NsUltimate\Business\Store\Service\StaffCommandService;

final class StoreCashRegister
{
    private const PAGE_SIZE = 50;
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StaffCommandService $commands,
        private readonly StoreCashMetadata $metadata,
    ) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function record(Store $store, string $actor, array $input): array
    {
        LedgerInput::fields($input, 'commandUuid', 'date', 'direction', 'category', 'amount', 'note', 'metadata');
        $direction = $input['direction'] ?? null;
        $category = $input['category'] ?? null;
        if (!is_string($direction) || !is_string($category) || !$this->categoryAvailable($store->getUuid(), $category, $direction)) {
            throw new LedgerError('invalid_cash_category');
        }
        $date = LedgerInput::date($input['date'] ?? null);
        $payload = [
            'commandUuid' => LedgerInput::uuid($input['commandUuid'] ?? null),
            'date' => $date, 'direction' => $direction, 'category' => $category,
            'amount' => LedgerInput::amount($input['amount'] ?? null),
            'note' => LedgerInput::text($input['note'] ?? '', 500, true),
            'metadata' => StoreCashMetadata::normalize($category, $input['metadata'] ?? []),
        ];

        return $this->commands->run($store, $actor, 'cash_entry.record', $payload, function () use ($store, $actor, $payload): array {
            $currency = $store->getCurrency();
            if (!preg_match('/\A[A-Z]{3}\z/', $currency)) {
                throw new LedgerError('invalid_currency');
            }
            $entry = new StoreCashEntry(
                $store->getUuid(), $actor, new \DateTimeImmutable($payload['date'], new \DateTimeZone('UTC')),
                $currency, $payload['direction'], $payload['category'], (int) $payload['amount'], $payload['note'], $payload['metadata'],
            );
            $this->em->persist($entry);

            return ['before' => [], 'result' => ['uuid' => $entry->getUuid()]];
        });
    }

    /** @return list<array{id: string, name: string, direction: string}> */
    public function categories(Store $store): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT c.category AS id, c.name, c.direction FROM ns_accounting_cash_category c
             JOIN ns_accounting_book_store b ON b.book_id = c.book_id
             WHERE HEX(b.store_uuid) = HEX(?) ORDER BY c.direction, c.name, c.category',
            [$store->getUuid()],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'name' => (string) $row['name'], 'direction' => (string) $row['direction'],
        ], $rows);
    }

    private function categoryAvailable(string $storeUuid, string $category, string $direction): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM ns_accounting_cash_category c JOIN ns_accounting_book_store b ON b.book_id = c.book_id
             WHERE HEX(b.store_uuid) = HEX(?) AND c.category = ? AND c.direction = ?',
            [$storeUuid, $category, $direction],
        );
    }

    /** @return array<string, mixed> */
    public function month(Store $store, ?string $month, int $page): array
    {
        $month ??= (new \DateTimeImmutable('now', new \DateTimeZone($store->getTimezone())))->format('Y-m');
        LedgerInput::date($month.'-01');
        $from = $month.'-01';
        $to = (new \DateTimeImmutable($from))->format('Y-m-t');
        $range = ' FROM ns_store_cash_entry WHERE store_uuid = ? AND entry_date BETWEEN ? AND ? AND voided_at IS NULL';
        $db = $this->em->getConnection();
        $args = [$store->getUuid(), $from, $to];
        $limit = self::PAGE_SIZE + 1;
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = $db->fetchAllAssociative('SELECT uuid, entry_date, currency, direction, category, amount, note, metadata'.$range
            .' ORDER BY entry_date DESC, created_at DESC, uuid DESC LIMIT '.$limit.' OFFSET '.$offset, $args);
        $totals = [];
        foreach ($db->iterateAssociative('SELECT amount, direction, category, currency'.$range, $args) as $row) {
            $currency = $row['currency'];
            $total = $totals[$currency] ?? ['income' => '0', 'expense' => '0', 'byCategory' => []];
            $direction = $row['direction'];
            $category = $row['category'];
            $key = $direction === 'income' ? 'income' : 'expense';
            $total[$key] = (string) BigInteger::of($total[$key])->plus((string) $row['amount']);
            $total['byCategory'][$category] = (string) BigInteger::of($total['byCategory'][$category] ?? '0')->plus((string) $row['amount']);
            $totals[$currency] = $total;
        }
        ksort($totals);

        return [
            'month' => $month, 'page' => $page, 'hasMore' => count($rows) > self::PAGE_SIZE,
            'totals' => array_map(static fn ($currency, $total) => ['currency' => $currency] + $total, array_keys($totals), array_values($totals)),
            'entries' => array_map($this->project(...), array_slice($rows, 0, self::PAGE_SIZE)),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function project(array $row): array
    {
        $metadata = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);

        return [
            'uuid' => $row['uuid'], 'date' => $row['entry_date'], 'currency' => $row['currency'], 'direction' => $row['direction'],
            'category' => $row['category'], 'amount' => (string) $row['amount'], 'note' => $row['note'],
            'source' => 'manual', 'metadata' => $metadata,
            'purchaseCost' => $row['category'] === 'purchase' ? $this->metadata->purchaseCost((int) $row['amount'], $metadata) : null,
        ];
    }
}
