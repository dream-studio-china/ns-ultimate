<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade\Controller\Manage;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Trade\Repository\OrderItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

final class OrderItemControllerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\\Trade\\Entity\\OrderItem')->execute();
        $this->em->createQuery('DELETE FROM App\\Trade\\Entity\\Order')->execute();
        $this->em->createQuery('DELETE FROM App\\Store\\Entity\\Specification')->execute();
        $this->em->createQuery('DELETE FROM App\\Store\\Entity\\Product')->execute();
        self::ensureKernelShutdown();
    }

    public function testListDetailUpdateAndDelete(): void
    {
        $client = static::createAuthenticatedClient();
        $item = $this->createOrderItem(500, 2);
        $id = $item->getId();

        $client->request('GET', '/api/v1/manage/order-items');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $list = $this->decode($client->getResponse()->getContent());
        self::assertContains($id, array_column($list['data'], 'id'));

        $client->request('GET', '/api/v1/manage/order-items/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $detail = $this->decode($client->getResponse()->getContent());
        self::assertSame($id, $detail['data']['id']);

        $client->request(
            'PUT',
            '/api/v1/manage/order-items/' . $id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['price' => 1200]),
        );
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $updated = $this->decode($client->getResponse()->getContent());
        self::assertSame(1200, $updated['data']['price']);

        $client->request('DELETE', '/api/v1/manage/order-items/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->getRepository(OrderItem::class)->find($id));
    }

    public function testDetailReturns404WhenMissing(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/v1/manage/order-items/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCreateWithoutRequiredPropertiesReturns400(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/manage/order-items',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['price' => 100]),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    #[Group('low-value')]
    public function testRepositoryFindByOrder(): void
    {
        self::ensureKernelShutdown();
        $item = $this->createOrderItem(300, 1);
        $orderId = (int) $item->getOrder()?->getId();

        /** @var OrderItemRepository $repo */
        $repo = $this->em->getRepository(OrderItem::class);
        self::assertInstanceOf(OrderItem::class, $repo->findById((int) $item->getId()));
        self::assertCount(1, $repo->findByOrder($orderId));
    }

    private function createOrderItem(int $unitPrice, int $quantity): OrderItem
    {
        $product = new Product();
        $product->setName('Picture Frame');
        $this->em->persist($product);

        $specification = new Specification();
        $specification->setProduct($product)->setName('Default')->setPrice($unitPrice);
        $this->em->persist($specification);

        $order = new Order();
        $order->setStatus(Order::STATUS_DRAFT);
        $this->em->persist($order);

        $item = new OrderItem();
        $item->setOrder($order)
            ->setSpecificationUuid($specification->getUuid())
            ->setSpecificationTitle($specification->getName())
            ->setSpecSnapshot([
                'uuid' => $specification->getUuid(),
                'name' => $specification->getName(),
            ])
            ->setProductSnapshot([
                'uuid' => $product->getUuid(),
                'name' => $product->getName(),
            ])
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice);
        $order->addItem($item);
        $this->em->persist($item);

        $this->em->flush();

        self::assertSame($specification->getUuid(), $item->getSpecificationUuid());

        return $item;
    }

    /** @return array<string, mixed> */
    private function decode(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
