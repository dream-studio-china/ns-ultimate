<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicOrderStoreApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private Order $order;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->store = new Store('public-store', 'Public store');
        // Metadata is deliberately different: store ownership must come from
        // the trusted Trade lifecycle, not caller-influenced order metadata.
        $this->order = (new Order())->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(100)->setCurrency('CNY')
            ->setMetadata(['_store' => ['uuid' => UUID::v4()]]);
        $this->em->persist($this->store);
        $this->em->flush();
        $this->em->wrapInTransaction(function (): void {
            $this->em->persist($this->order);
            $this->em->persist(new OrderStoreLifecycle($this->order->getUuid(), $this->store->getUuid()));
        });
    }

    public function testAnonymousLookupReturnsOnlyOrderAndStoreUuids(): void
    {
        $response = $this->request();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame([
            'code' => 0,
            'message' => 'SUCCESS',
            'data' => ['orderUuid' => $this->order->getUuid(), 'storeUuid' => $this->store->getUuid()],
        ], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('_store', $response->getContent());
        self::assertSame(200, $this->request(strtoupper($this->order->getUuid()))->getStatusCode());
    }

    public function testUnknownMalformedOrUnboundOrdersReturnNotFound(): void
    {
        foreach ([UUID::v4(), '123', 'not-a-uuid'] as $uuid) {
            self::assertSame(404, $this->request($uuid)->getStatusCode());
        }

        $this->em->getConnection()->executeStatement(
            'DELETE FROM trade_order_store_lifecycle WHERE trade_order_uuid = ?',
            [$this->order->getUuid()],
        );
        $this->em->clear();
        self::assertSame(404, $this->request()->getStatusCode());
    }

    public function testInactiveStoreIsNotPubliclyResolvable(): void
    {
        $this->store->suspend();
        $this->em->flush();
        self::assertSame(404, $this->request()->getStatusCode());
    }

    private function request(?string $uuid = null): Response
    {
        $response = self::$kernel->handle(Request::create('/api/v1/public/orders/'.($uuid ?? $this->order->getUuid()).'/store'));
        $this->store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]) ?? $this->store;
        $this->order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $this->order->getUuid()]) ?? $this->order;
        return $response;
    }
}
