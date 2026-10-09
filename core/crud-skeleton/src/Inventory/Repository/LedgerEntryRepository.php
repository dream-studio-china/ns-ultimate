<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\LedgerEntry;
use App\Inventory\Entity\Material;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LedgerEntry> */
class LedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
    }

    public function findOneByOperation(string $type, string $referenceId, string $storeUuid, Material $material): ?LedgerEntry
    {
        return $this->findOneBy([
            'type' => $type,
            'referenceId' => $referenceId,
            'storeUuid' => $storeUuid,
            'material' => $material,
        ]);
    }
}
