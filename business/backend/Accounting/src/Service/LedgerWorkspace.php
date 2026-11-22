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
