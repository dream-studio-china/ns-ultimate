<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Reservation> */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function findOneByReservationId(string $reservationId): ?Reservation
    {
        return $this->findOneBy(['reservationId' => $reservationId]);
    }

    public function findOneByStoreOrderUuid(string $storeOrderUuid): ?Reservation
    {
        return $this->findOneBy(['storeOrderUuid' => $storeOrderUuid]);
    }

    /** @return list<Reservation> */
    public function findExpiredConfirmed(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('reservation')
            ->andWhere('reservation.status = :status')
            ->andWhere('reservation.expiresAt IS NOT NULL')
            ->andWhere('reservation.expiresAt <= :now')
            ->setParameter('status', Reservation::STATUS_CONFIRMED)
            ->setParameter('now', $now)
            ->orderBy('reservation.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
