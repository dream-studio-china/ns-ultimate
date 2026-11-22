<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\AccountingPeriod;

final class LedgerWorkspace
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly LedgerAccountService $accounts) {}

    /** @return array<string, mixed> */
    public function setup(): array
    {
        return [
            'book' => $this->book(),
            'accounts' => $this->accounts->chart(),
            'periods' => $this->periods(),
        ];
    }

    /** @return array<string, int|string>|null */
    public function book(): ?array
    {
        $book = $this->em->find(AccountingBook::class, AccountingBook::ID);

        return $book === null ? null : [
            'id' => $book->getId(), 'entity' => $book->getEntity(), 'currency' => $book->getCurrency(),
            'scale' => $book->getScale(), 'timezone' => $book->getTimezone(),
            'opening_date' => $book->getOpeningDate()->format('Y-m-d'), 'opening_state' => $book->getOpeningState(),
        ];
    }

    /** @return list<array{month: string, state: string}> */
    public function periods(): array
    {
        $periods = $this->em->getRepository(AccountingPeriod::class)->findBy(['book' => AccountingBook::ID], ['month' => 'DESC']);

        return array_map(static fn (AccountingPeriod $period) => ['month' => $period->getMonth(), 'state' => $period->getState()], $periods);
    }

    /** @return array{month: string, state: string}|null */
    public function period(string $month): ?array
    {
        $period = $this->em->find(AccountingPeriod::class, ['book' => AccountingBook::ID, 'month' => $month]);

        return $period === null ? null : ['month' => $period->getMonth(), 'state' => $period->getState()];
    }
}
