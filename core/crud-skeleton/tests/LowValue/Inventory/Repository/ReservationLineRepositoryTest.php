<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Inventory\Repository;


use PHPUnit\Framework\Attributes\Group;
use App\Inventory\Entity\Reservation;
use App\Inventory\Entity\Material;
use App\Inventory\Entity\ReservationLine;
use App\Inventory\Repository\ReservationLineRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

#[Group('low-value')]
final class ReservationLineRepositoryTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private ReservationLineRepository $repo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Inventory\\Entity\\ReservationLine line')->execute();
        $this->em->createQuery('DELETE FROM App\\Inventory\\Entity\\Reservation reservation')->execute();
        $this->em->createQuery('DELETE FROM App\\Inventory\\Entity\\Material material')->execute();

        $repo = $this->em->getRepository(ReservationLine::class);
        self::assertInstanceOf(ReservationLineRepository::class, $repo);
        $this->repo = $repo;
    }

    /**
     * @return array{Material, Reservation}
     */
    private function createReservation(string $reservationId, string $materialCode): array
    {
        $material = new Material($materialCode, 'Reservation material', Material::KIND_FINISHED, 'piece');
        $storeUuid = substr($reservationId, 0, 35) . '1';
        $tradeOrderUuid = substr($reservationId, 0, 35) . '2';
        $storeOrderUuid = $reservationId;
        $reservation = new Reservation(
            $reservationId,
            $storeUuid,
            $tradeOrderUuid,
            $storeOrderUuid,
            str_repeat('a', 64),
            new \DateTimeImmutable('+1 day'),
        );
        $reservation->addLine(new ReservationLine($material, '2.000000', ['00000000-0000-4000-8000-000000000104']));
        $this->em->persist($material);
        $this->em->persist($reservation);
        $this->em->flush();

        return [$material, $reservation];
    }

    public function testRepositoryIsInstantiatedByTheContainer(): void
    {
        $fromContainer = static::getContainer()->get(ReservationLineRepository::class);
        self::assertInstanceOf(ReservationLineRepository::class, $fromContainer);
        self::assertSame(ReservationLine::class, $fromContainer->getClassName());
    }

    public function testFindAllAndFindByMaterialReturnPersistedLines(): void
    {
        [$firstMaterial] = $this->createReservation('00000000-0000-4000-8000-000000000111', 'reservation-line-a');
        [$secondMaterial] = $this->createReservation('00000000-0000-4000-8000-000000000112', 'reservation-line-b');

        self::assertCount(2, $this->repo->findAll());

        $byFirstMaterial = $this->repo->findBy(['materialUuid' => $firstMaterial->getUuid()]);
        self::assertCount(1, $byFirstMaterial);
        self::assertSame('2.000000', $byFirstMaterial[0]->getReservedQuantity());
        self::assertSame($firstMaterial->getUuid(), $byFirstMaterial[0]->getMaterialUuid());

        $bySecondMaterial = $this->repo->findBy(['materialUuid' => $secondMaterial->getUuid()]);
        self::assertCount(1, $bySecondMaterial);
    }

    public function testFindByReservationReturnsItsLines(): void
    {
        [$material, $reservation] = $this->createReservation('00000000-0000-4000-8000-000000000113', 'reservation-line-c');

        $lines = $this->repo->findBy(['reservation' => $reservation]);

        self::assertCount(1, $lines);
        self::assertSame($material->getUuid(), $lines[0]->getMaterialUuid());
        self::assertSame('2.000000', $lines[0]->getReservedQuantity());
    }

    public function testFindByIdReturnsLineAndUnknownIdReturnsNull(): void
    {
        $this->createReservation('00000000-0000-4000-8000-000000000114', 'reservation-line-d');
        $line = $this->repo->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(ReservationLine::class, $line);

        $id = (new \ReflectionProperty(ReservationLine::class, 'id'))->getValue($line);
        self::assertNotNull($id);
        self::assertSame($line, $this->repo->find($id));
        self::assertNull($this->repo->find(999999));
    }

    public function testRemovePersistedLine(): void
    {
        $this->createReservation('00000000-0000-4000-8000-000000000115', 'reservation-line-e');
        $materialUuid = $this->repo->findOneBy([], ['id' => 'ASC'])->getMaterialUuid();
        $line = $this->repo->findOneBy(['materialUuid' => $materialUuid]);
        self::assertInstanceOf(ReservationLine::class, $line);
        $this->em->remove($line);
        $this->em->flush();

        self::assertNull($this->repo->findOneBy(['materialUuid' => $materialUuid]));
    }
}
