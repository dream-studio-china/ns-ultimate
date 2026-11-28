<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminCounterOrdersTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    public function testFilteringAndSessionGraph(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $store = new Store('front', 'Front_Store');
        $admin = (new User())->setEmail('orders@example.test')->setUsername('orders-admin')->setRoles(['ROLE_ADMIN']);
        $guest = (new User())->setEmail('guest@example.test')->setUsername('orders-guest');
        $counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('A01')->setName('Window');
        $session = new CounterSession($counter);
        $session->end();
        $otherSession = new CounterSession($counter);
        $source = (new Order())->setUser($guest)->setStatus('merged');
        $source->addItem((new OrderItem())->setSpecificationTitle('Coffee')->setQuantity(2));
        $middle = (new Order())->setStatus('merged');
        $target = (new Order())->setStatus('cancelled');
        $standalone = (new Order())->setStatus('pending');
        foreach (range(1, 13) as $line) {
            $standalone->addItem((new OrderItem())->setSpecificationTitle('Item '.$line)->setUnitPrice(250)->setPrice(250));
        }
        $other = (new Order())->setStatus('paid');
        $legacy = (new Order())->setStatus('completed');
        $projection = new StoreOrder($store, $source->getUuid(), 'front', 'Front_Store', $guest->getUuid(), 'CNY', 0, []);
        foreach ([$store, $admin, $guest, $counter, $session, $otherSession, $projection] as $entity) {
            $em->persist($entity);
        }
        foreach ([$source, $middle, $target, $standalone, $other, $legacy] as $order) {
            $em->persist($order);
            $em->persist(new OrderStoreLifecycle($order->getUuid(), $store->getUuid(), $order === $source ? $projection->getUuid() : null));
        }
        $em->persist(new OrderMerge($source->getUuid(), $middle->getUuid()));
        $em->persist(new OrderMerge($middle->getUuid(), $target->getUuid()));
        $em->flush();
        // Model historic bindings directly: production writes may only bind to the active session.
        foreach ([$source, $middle, $target, $standalone, $other, $legacy] as $order) {
            $em->getConnection()->insert('counter_order', [
                'uuid' => UUID::v4(), 'trade_order_uuid' => $order->getUuid(), 'counter_id' => $counter->getId(),
                'counter_session_id' => $order === $legacy ? null : ($order === $other ? $otherSession : $session)->getId(),
                'store_uuid' => $store->getUuid(), 'counter_code_snapshot' => $counter->getCode(),
                'counter_name_snapshot' => $counter->getName(), 'created_at' => '2026-11-01 10:00:00',
            ]);
        }
        $base = '/api/v1/manage/counter-orders';
        $tokens = self::getContainer()->get(TokenManager::class);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens->createAccessToken($admin)];
        $get = static function (string $path = '', array $params = []) use ($base, $headers): array {
            $response = self::$kernel->handle(Request::create($base.$path, 'GET', $params, [], [], $headers));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        };
        $nonAdmin = ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens->createAccessToken($guest)];
        self::assertSame(403, self::$kernel->handle(Request::create($base, 'GET', [], [], [], $nonAdmin))->getStatusCode());
        self::assertSame($store->getUuid(), $get('/stores', ['q' => 'front_'])['items'][0]['uuid']);
        self::assertSame([], $get('/stores', ['q' => '%'])['items']);
        self::assertSame($counter->getUuid(), $get('', ['order' => $projection->getUuid(), 'user' => $guest->getUuid()])['items'][0]['uuid']);
        self::assertSame($counter->getUuid(), $get('', ['order' => $source->getUuid(), 'operational' => 'pending_validation'])['items'][0]['uuid']);
        self::assertSame([], $get('', ['user' => $guest->getUuid(), 'status' => 'cancelled'])['items']);
        self::assertSame([], $get('', ['anonymous' => 'yes', 'user' => $guest->getUuid()])['items']);
        $path = '/'.$counter->getUuid().'/orders';
        $history = $get($path)['items'];
        self::assertSame($legacy->getUuid(), $history[0]['uuid']);
        self::assertNull($history[0]['sessionUuid']);
        self::assertSame($otherSession->getUuid(), $history[1]['sessionUuid']);
        self::assertSame([$source->getUuid()], array_column($get($path, ['anonymous' => 'no'])['items'], 'uuid'));
        self::assertSame([], $get($path, ['before' => '2000-01-01T00:00:00Z'])['items']);
        self::assertSame([$target->getUuid(), $middle->getUuid(), $source->getUuid()], array_column($get($path, ['merged' => 'yes'])['items'], 'uuid'));
        $graph = $get($path.'/'.$source->getUuid().'/graph');
        self::assertSame([$standalone->getUuid(), $target->getUuid(), $middle->getUuid(), $source->getUuid()], array_column($graph['nodes'], 'uuid'));
        self::assertCount(2, $graph['edges']);
        self::assertCount(12, $graph['nodes'][0]['items']);
        self::assertSame(13, $graph['nodes'][0]['itemCount']);
        self::assertSame([['name' => 'Coffee', 'quantity' => 2]], $graph['nodes'][3]['items']);
        self::assertFalse($graph['truncated']);
        self::assertSame([$legacy->getUuid()], array_column($get($path.'/'.$legacy->getUuid().'/graph')['nodes'], 'uuid'));

        $itemsPath = '/api/v1/manage/orders/'.$standalone->getUuid().'/items';
        $response = self::$kernel->handle(Request::create($itemsPath, 'GET', [], [], [], $headers));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $items = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertCount(13, $items);
        self::assertContains('Item 13', array_column($items, 'specificationTitle'));
        self::assertSame(250, $items[0]['unitPrice']);
    }
}
