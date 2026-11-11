<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Promotion\Entity\Promotion;
use App\Promotion\Service\PromotionTemplateServiceInterface;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderPromotionMetadataTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $token;
    private int $specificationId;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $admin = (new User())->setUsername('promotion-admin')->setEmail('promotion-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $product = (new Product())->setName('Promotion product');
        $specification = (new Specification())->setProduct($product)->setName('Regular')->setPrice(15000);
        foreach ([$admin, $product, $specification] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->specificationId = $specification->getId();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
    }

    public static function creationPaths(): iterable
    {
        yield 'administrator' => ['/api/v1/manage/orders'];
        yield 'customer' => ['/api/v1/app/orders'];
    }

    #[DataProvider('creationPaths')]
    public function testCreationPersistsOnlyItsOwnQuoteAndOverwritesForgedSnapshot(string $path): void
    {
        $this->createPromotion();
        $response = $this->request('POST', $path, $this->body());
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $order = $this->data($response);
        self::assertSame(25000, $order['totalAmount']);
        $snapshot = $order['metadata']['_promotion'];
        self::assertCount(1, $snapshot['applied']);
        self::assertSame('Order promotion', $snapshot['applied'][0]['name']);
        self::assertSame('inner', $snapshot['applied'][0]['phase']);
        self::assertArrayNotHasKey('config', $snapshot['applied'][0]);
        self::assertSame('kept', $order['metadata']['custom']);
        self::assertNotFalse(strtotime($snapshot['recordedAt']));

        // A different quote in the same kernel must not leak into this creation.
        $this->request('POST', $path.'/quote', ['items' => [['specificationId' => $this->specificationId, 'quantity' => 3]]]);
        $this->em->getConnection()->executeStatement('UPDATE promotion SET enabled = false');
        $this->em->clear();
        $second = $this->request('POST', $path, $this->body());
        self::assertSame(201, $second->getStatusCode(), $second->getContent());
        self::assertSame([], $this->data($second)['metadata']['_promotion']['applied']);
        self::assertSame(30000, $this->data($second)['totalAmount']);

        $detail = $this->request('GET', $path.'/'.$order['uuid']);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame($snapshot, $this->data($detail)['metadata']['_promotion']);
        $list = $this->request('GET', $path);
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(2, $this->data($list));
    }

    public function testDraftMetadataUpdatesCannotForgeRemoveOrReplaceSnapshot(): void
    {
        $this->createPromotion();
        $created = $this->request('POST', '/api/v1/manage/orders', $this->body());
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $order = $this->data($created);
        foreach ([['_promotion' => ['applied' => [['name' => 'Forged']]], 'custom' => 'updated'], ['custom' => 'only'], null] as $metadata) {
            $response = $this->request('PUT', '/api/v1/manage/orders/'.$order['uuid'], ['metadata' => $metadata]);
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            self::assertSame($order['metadata']['_promotion'], $this->data($response)['metadata']['_promotion']);
        }

        $legacy = new Order();
        $this->em->persist($legacy); $this->em->flush();
        $response = $this->request('PUT', '/api/v1/manage/orders/'.$legacy->getUuid(), ['metadata' => ['_promotion' => ['applied' => []], 'custom' => 'kept']]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame(['custom' => 'kept'], $this->data($response)['metadata']);
    }

    public function testDirectServiceQuotesHaveNoImplicitEffectOnCreation(): void
    {
        $this->createPromotion();
        $service = self::getContainer()->get(OrderServiceInterface::class);
        $quote = $service->calculatePrices($this->body()['items']);
        $order = $service->createOrder($quote->items, null, $quote->totalAmount);
        self::assertArrayNotHasKey('_promotion', $order->getMetadata() ?? []);
    }

    public function testStoreCurrencyMismatchAndInvalidItemsStillFailWithoutWrites(): void
    {
        $store = new \App\Store\Entity\Store('PROMO-STORE', 'Promotion store');
        $store->activate();
        $this->em->persist($store); $this->em->flush();
        $request = Request::create('/api/v1/manage/orders', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_STORE_CODE' => $store->getCode(),
        ], content: json_encode($this->body() + ['currency' => 'USD'], JSON_THROW_ON_ERROR));
        self::assertSame(400, self::$kernel->handle($request)->getStatusCode());
        foreach ([[], ['bad'], ['named' => ['specificationId' => $this->specificationId]]] as $items) {
            $response = $this->request('POST', '/api/v1/manage/orders', ['items' => $items]);
            self::assertSame(400, $response->getStatusCode(), $response->getContent());
        }
        self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }

    public function testBatchCreationCarriesEachRowsQuoteExplicitly(): void
    {
        $this->createPromotion();
        $first = $this->body();
        $second = $this->body();
        $second['items'][0]['quantity'] = 3;
        $response = $this->request('POST', '/api/v1/manage/orders', [$first, $second]);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $rows = $this->data($response);
        self::assertCount(2, $rows);
        self::assertSame([25000, 40000], array_column($rows, 'totalAmount'));
        foreach ($rows as $row) {
            self::assertSame('Order promotion', $row['metadata']['_promotion']['applied'][0]['name']);
        }
    }

    public function testCustomerCannotUseAdministratorRoutesOrReadOtherOrders(): void
    {
        $other = new Order(); $this->em->persist($other);
        $user = (new User())->setUsername('ordinary')->setEmail('ordinary@example.test');
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        self::assertSame(403, $this->request('POST', '/api/v1/manage/orders', $this->body())->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/v1/app/orders/'.$other->getUuid())->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return ['items' => [['specificationId' => $this->specificationId, 'quantity' => 2]],
            'metadata' => ['custom' => 'kept', '_promotion' => ['applied' => [['name' => 'Forged']]]]];
    }

    /** @param array<mixed> $body */
    private function request(string $method, string $path, array $body = []): Response
    {
        return self::$kernel->handle(Request::create($path, $method,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<mixed> */
    private function data(Response $response): array
    {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    private function createPromotion(): void
    {
        $templates = self::getContainer()->get(PromotionTemplateServiceInterface::class);
        $template = $templates->update($templates->new(), ['name' => 'Promotion template', 'type' => 'full_reduction',
            'phase' => 0, 'enabled' => true, 'dsl' => "type: full_reduction\ndo:\n  discount order 50.00\n"]);
        self::assertNotNull($template);
        $promotion = (new Promotion())->setName('Order promotion')->setTemplate($template)->setEnabled(true);
        $this->em->persist($promotion); $this->em->flush();
    }
}
