<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TradeRepoFullTest extends KernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testProductRepoFindNotDeletedAndFindActive(): void
    {
        $activeProduct = new Product();
        $activeProduct->setName('Active P1');
        $this->em->persist($activeProduct);

        $inactiveProduct = new Product();
        $inactiveProduct->setName('Inactive P1');
        $inactiveProduct->setStatus('inactive');
        $this->em->persist($inactiveProduct);

        $deletedProduct = new Product();
        $deletedProduct->setName('Deleted P1');
        $deletedProduct->setIsDeleted(true);
        $this->em->persist($deletedProduct);
        $this->em->flush();

        $repo = $this->em->getRepository(Product::class);
        $notDeleted = $repo->findNotDeleted();
        $names = array_map(fn($p) => $p->getName(), $notDeleted);
        self::assertNotContains('Deleted P1', $names);
        self::assertContains('Active P1', $names);

        $active = $repo->findActive();
        $activeNames = array_map(fn($p) => $p->getName(), $active);
        self::assertNotContains('Inactive P1', $activeNames);
        self::assertNotContains('Deleted P1', $activeNames);
    }

    public function testSpecRepoFindByProductAndFindActiveByProduct(): void
    {
        $product = new Product();
        $product->setName('SpecTest');
        $this->em->persist($product);

        $spec1 = new Specification();
        $spec1->setProduct($product);
        $spec1->setName('ActiveSpec');
        $spec1->setPrice(100);
        $this->em->persist($spec1);

        $spec2 = new Specification();
        $spec2->setProduct($product);
        $spec2->setName('InactiveSpec');
        $spec2->setPrice(200);
        $spec2->setStatus('inactive');
        $this->em->persist($spec2);

        $spec3 = new Specification();
        $spec3->setProduct($product);
        $spec3->setName('DeletedSpec');
        $spec3->setPrice(300);
        $spec3->setIsDeleted(true);
        $this->em->persist($spec3);
        $this->em->flush();

        $repo = $this->em->getRepository(Specification::class);
        $byProduct = $repo->findByProduct($product->getId() ?? 0);
        $names = array_map(fn($s) => $s->getName(), $byProduct);
        self::assertContains('ActiveSpec', $names);
        self::assertContains('InactiveSpec', $names);
        self::assertNotContains('DeletedSpec', $names);

        $activeByProduct = $repo->findActiveByProduct($product->getId() ?? 0);
        $activeNames = array_map(fn($s) => $s->getName(), $activeByProduct);
        self::assertContains('ActiveSpec', $activeNames);
        self::assertNotContains('InactiveSpec', $activeNames);
    }

    public function testOrderRepoFindByUser(): void
    {
        $order = new Order();
        $order->setTotalAmount(100);
        $this->em->persist($order);
        $this->em->flush();

        $repo = $this->em->getRepository(Order::class);
        $found = $repo->findById($order->getId() ?? 0);
        self::assertNotNull($found);

        $byUser = $repo->findByUser(0);
        self::assertIsArray($byUser);
    }

    public function testOrderItemRepoFindByOrder(): void
    {
        $product = new Product();
        $product->setName('ItemTest');
        $this->em->persist($product);

        $spec = new Specification();
        $spec->setProduct($product);
        $spec->setName('ItemSpec');
        $spec->setPrice(100);
        $this->em->persist($spec);

        $order = new Order();
        $order->setTotalAmount(500);
        $this->em->persist($order);

        $item = new OrderItem();
        $item->setSpecificationUuid($spec->getUuid())
            ->setSpecificationTitle($spec->getName())
            ->setSpecSnapshot([
                'uuid' => $spec->getUuid(),
                'name' => $spec->getName(),
            ])
            ->setProductSnapshot([
                'uuid' => $product->getUuid(),
                'name' => $product->getName(),
            ])
            ->setQuantity(5)
            ->setUnitPrice(100);
        $order->addItem($item);
        $this->em->flush();

        $repo = $this->em->getRepository(OrderItem::class);
        $byOrder = $repo->findByOrder($order->getId() ?? 0);
        self::assertCount(1, $byOrder);

        $found = $repo->findById($item->getId() ?? 0);
        self::assertNotNull($found);
        self::assertSame($spec->getUuid(), $found->getSpecificationUuid());
    }
}
