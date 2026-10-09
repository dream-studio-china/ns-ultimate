<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade\Repository;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Store\Repository\SpecificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SpecificationRepositoryTest extends KernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private SpecificationRepository $repo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Store\\Entity\\Specification s')->execute();
        $this->em->createQuery('DELETE FROM App\\Store\\Entity\\Product p')->execute();

        /** @var SpecificationRepository $repo */
        $repo = $this->em->getRepository(Specification::class);
        $this->repo = $repo;
    }

    private function createSpecification(): Specification
    {
        $product = new Product();
        $product->setName('Spec Repo Product');
        $this->em->persist($product);

        $spec = new Specification();
        $spec->setProduct($product);
        $spec->setName('Specification A');
        $spec->setPrice(1000);
        $this->em->persist($spec);
        $this->em->flush();

        return $spec;
    }

    #[Group('low-value')]
    public function testFindByIdReturnsSpecification(): void
    {
        $spec = $this->createSpecification();
        $this->em->clear();

        $found = $this->repo->findById((int) $spec->getId());

        self::assertInstanceOf(Specification::class, $found);
        self::assertSame($spec->getId(), $found->getId());
        self::assertSame('Specification A', $found->getName());
    }

    #[Group('low-value')]
    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function testFindByIdForUpdateReturnsSpecification(): void
    {
        $spec = $this->createSpecification();
        $this->em->clear();

        $this->em->getConnection()->beginTransaction();
        try {
            $locked = $this->repo->findByIdForUpdate((int) $spec->getId());
            self::assertInstanceOf(Specification::class, $locked);
            self::assertSame($spec->getId(), $locked->getId());
            self::assertSame('Specification A', $locked->getName());
            $this->em->getConnection()->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            throw $e;
        }
    }

    public function testFindByIdForUpdateReturnsNullForUnknownId(): void
    {
        $this->em->getConnection()->beginTransaction();
        try {
            self::assertNull($this->repo->findByIdForUpdate(999999));
            $this->em->getConnection()->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            throw $e;
        }
    }
}
