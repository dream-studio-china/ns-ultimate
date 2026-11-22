<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\AccountingBookStore;
use NsUltimate\Business\Accounting\Entity\AccountingPeriod;

final class LedgerWorkspace
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly LedgerAccountService $accounts) {}

    /** @return array<string, mixed> */
    public function setup(int $bookId = AccountingBook::ID): array
    {
        return [
            'book' => $this->book($bookId),
            'accounts' => $this->accounts->chart($bookId),
            'periods' => $this->periods($bookId),
        ];
    }

    /** @return array<string, int|string|null>|null */
    public function book(int $bookId = AccountingBook::ID): ?array
    {
        $book = $this->em->find(AccountingBook::class, $bookId);

        return $book === null ? null : $this->project($book);
    }

    public function forStore(string $uuid): int
    {
        $binding = $this->em->find(AccountingBookStore::class, $uuid);

        return $binding?->getBook()->getId() ?? throw new LedgerError('book_not_found', 404);
    }

    /** @return list<string> */
    public function stores(int $bookId): array
    {
        return array_map(static fn (AccountingBookStore $binding) => $binding->getStoreUuid(),
            $this->em->getRepository(AccountingBookStore::class)->findBy(['book' => $bookId], ['storeUuid' => 'ASC']));
    }

    /** @return list<string> */
    public function mappedCashCategories(int $bookId): array
    {
        return array_map(static fn (array $row) => (string) $row['category'],
            $this->em->getConnection()->fetchAllAssociative('SELECT category FROM ns_accounting_cash_mapping WHERE book_id = ? ORDER BY category', [$bookId]));
    }

    /** @return list<array{id: string, name: string, direction: string}> */
    public function cashCategories(int $bookId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT category AS id, name, direction FROM ns_accounting_cash_category WHERE book_id = ? ORDER BY name, category',
            [$bookId],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'name' => (string) $row['name'], 'direction' => (string) $row['direction'],
        ], $rows);
    }

    /** @return list<array{uuid: string, code: string, name: string, currency: string}> */
    public function cashStores(int $bookId): array
    {
        return array_map(static fn (array $row) => [
            'uuid' => (string) $row['uuid'], 'code' => (string) $row['code'],
            'name' => (string) $row['name'], 'currency' => (string) $row['currency'],
        ], $this->em->getConnection()->fetchAllAssociative(
            'SELECT s.uuid, s.code, s.name, s.currency FROM ns_accounting_book_store b
             JOIN store s ON HEX(s.uuid) = HEX(b.store_uuid) WHERE b.book_id = ? ORDER BY s.name, s.uuid',
            [$bookId],
        ));
    }

    /** @return list<array{uuid: string, code: string, name: string}> */
    public function availableStores(string $search): array
    {
        $db = $this->em->getConnection();
        // Store and Accounting tables may use different MySQL collations.
        // Compare the bytes of their canonical UUIDs, not their collations.
        $query = $db->createQueryBuilder()->select('s.uuid', 's.code', 's.name')->from('store', 's')
            ->where('NOT EXISTS (SELECT 1 FROM ns_accounting_book_store binding WHERE HEX(binding.store_uuid) = HEX(s.uuid))')
            ->orderBy('s.name', 'ASC')->addOrderBy('s.uuid', 'ASC')->setMaxResults(30);
        if ($search !== '') {
            $platform = $db->getDatabasePlatform();
            $name = $platform->getLocateExpression('LOWER(s.name)', ':search');
            $code = $platform->getLocateExpression('LOWER(s.code)', ':search');
            $query->andWhere('('.$name.' > 0 OR '.$code.' > 0)')
                ->setParameter('search', mb_strtolower($search));
        }

        return array_values(array_map(static fn (array $row) => [
            'uuid' => (string) $row['uuid'], 'code' => (string) $row['code'], 'name' => (string) $row['name'],
        ], $query->fetchAllAssociative()));
    }

    public function books(int $page, int $limit): LedgerPage
    {
        $repo = $this->em->getRepository(AccountingBook::class);
        $rows = $repo->findBy([], ['id' => 'ASC'], $limit, ($page - 1) * $limit);

        return new LedgerPage(array_map($this->project(...), $rows), $repo->count([]), $page, $limit);
    }

    /** @return array<string, int|string|null> */
    private function project(AccountingBook $book): array
    {
        return [
            'id' => $book->getId(), 'entity' => $book->getEntity(), 'currency' => $book->getCurrency(),
            'store_uuid' => $book->getStoreUuid(),
            'scale' => $book->getScale(), 'timezone' => $book->getTimezone(),
            'opening_date' => $book->getOpeningDate()->format('Y-m-d'), 'opening_state' => $book->getOpeningState(),
        ];
    }

    /** @return list<array{month: string, state: string}> */
    public function periods(int $bookId = AccountingBook::ID): array
    {
        $periods = $this->em->getRepository(AccountingPeriod::class)->findBy(['book' => $bookId], ['month' => 'DESC']);

        return array_map(static fn (AccountingPeriod $period) => ['month' => $period->getMonth(), 'state' => $period->getState()], $periods);
    }

    /** @return array{month: string, state: string}|null */
    public function period(string $month, int $bookId = AccountingBook::ID): ?array
    {
        $period = $this->em->find(AccountingPeriod::class, ['book' => $bookId, 'month' => $month]);

        return $period === null ? null : ['month' => $period->getMonth(), 'state' => $period->getState()];
    }
}
