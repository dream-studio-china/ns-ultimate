<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Trade\Repository;


use PHPUnit\Framework\Attributes\Group;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Store\Entity\Product;
use App\Store\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('low-value')]
final class ProductRepositoryTest extends KernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private ProductRepository $repo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Store\\Entity\\Product p')->execute();

        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $this->repo = $repo;
    }

    public function testFindByIdReturnsProduct(): void
    {
        $product = new Product();
        $product->setName('Repo Product');
        $this->em->persist($product);
        $this->em->flush();
        $this->em->clear();

        $found = $this->repo->findById((int) $product->getId());

        self::assertInstanceOf(Product::class, $found);
        self::assertSame($product->getId(), $found->getId());
        self::assertSame('Repo Product', $found->getName());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repo->findById(999999));
    }
}
