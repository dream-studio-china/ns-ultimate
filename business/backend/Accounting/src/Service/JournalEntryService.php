<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use App\Core\Service\BaseService;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Accounting\Entity\JournalEntry;
use NsUltimate\Business\Accounting\Repository\JournalEntryRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<JournalEntry> */
final class JournalEntryService extends BaseService
{
    private const PAGE_SIZE = 100;

    public function __construct(ContainerInterface $container, private readonly JournalEntryRepository $journals)
    {
        parent::__construct($container, JournalEntry::class);
    }

    /** @param array<string, mixed> $parameters */
    public function registerQuery(array $parameters): QueryBuilder
    {
        LedgerInput::fields($parameters, 'from', 'to', 'state', 'account', 'page');

        return $this->journals->register($parameters);
    }

    /** @return object{rows: list<array<string, int|string|null>>, page: int, has_more: bool} */
    public function registerPage(QueryBuilder $query, int $page): object
    {
        $entries = $query->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE + 1)->getQuery()->getResult();

        return (object) [
            'rows' => array_values(array_map(static fn (JournalEntry $entry) => [
                'id' => $entry->getUuid(), 'accounting_date' => $entry->getAccountingDate()->format('Y-m-d'),
                'summary' => $entry->getSummary(), 'origin' => $entry->getOrigin(), 'state' => $entry->getState(),
                'number' => $entry->getNumber(), 'reversal_of' => $entry->getReversalOf(),
            ], array_slice($entries, 0, self::PAGE_SIZE))),
            'page' => $page,
            'has_more' => count($entries) > self::PAGE_SIZE,
        ];
    }

    public function new(): never { throw new \LogicException('Use the ledger commands to create a journal.'); }

    /** @param array<string, mixed>|null $data */
    public function update(mixed $object, ?array $data = null, bool $noFlush = false): never
    {
        throw new \LogicException('Use the ledger commands to update a journal.');
    }

    public function remove($object): never { throw new \LogicException('Ledger journals cannot be deleted.'); }
}
