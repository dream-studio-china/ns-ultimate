<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\JournalEntry;
use NsUltimate\Business\Accounting\Entity\JournalLine;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;

/** @extends ServiceEntityRepository<JournalEntry> */
final class JournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalEntry::class);
    }

    /** @param array<string, mixed> $parameters */
    public function register(array $parameters, int $bookId = AccountingBook::ID, ?string $storeUuid = null): QueryBuilder
    {
        $query = $this->createQueryBuilder('entity')
            ->andWhere('IDENTITY(entity.book) = :book')->setParameter('book', $bookId)
            ->orderBy('entity.accountingDate', 'DESC')->addOrderBy('entity.sequenceNo', 'DESC')->addOrderBy('entity.uuid', 'DESC');
        if ($storeUuid !== null) {
            $query->andWhere('entity.storeUuid = :store')->setParameter('store', $storeUuid);
        }
        $from = isset($parameters['from']) ? LedgerInput::date($parameters['from']) : null;
        $to = isset($parameters['to']) ? LedgerInput::date($parameters['to']) : null;
        if ($from !== null) {
            $query->andWhere('entity.accountingDate >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $query->andWhere('entity.accountingDate <= :to')->setParameter('to', $to);
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new LedgerError('invalid_range');
        }
        if (isset($parameters['state'])) {
            if (!in_array($parameters['state'], ['draft', 'posted', 'voided'], true)) {
                throw new LedgerError('invalid_option');
            }
            $query->andWhere('entity.state = :state')->setParameter('state', $parameters['state']);
        }
        if (isset($parameters['account'])) {
            $lines = $this->getEntityManager()->createQueryBuilder()->select('1')->from(JournalLine::class, 'line')
                ->where('line.bookId = IDENTITY(entity.book) AND line.journalId = entity.uuid AND line.accountCode = :account');
            $query->andWhere($query->expr()->exists($lines->getDQL()))
                ->setParameter('account', LedgerInput::code($parameters['account']));
        }

        return $query;
    }
}
