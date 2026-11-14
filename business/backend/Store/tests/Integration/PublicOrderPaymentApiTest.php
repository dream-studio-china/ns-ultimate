<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchantStoreGrant;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicOrderPaymentApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private Counter $counter;
    private Order $order;
    private HuifuProviderProfile $provider;
    private HuifuMerchant $merchant;
    private StoreHuifuSetting $setting;
    private HuifuMerchantStoreGrant $grant;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $this->store = new Store('public-payment', 'Public payment store');
        $this->counter = (new Counter())->setStoreUuid($this->store->getUuid())->setCode('A01')->setName('A01');
        $payer = (new User())->setUsername('private-customer')->setEmail('private-customer@example.test');
        $this->order = (new Order())->setUser($payer)->setStatus(Order::STATUS_CONFIRMED)
            ->setTotalAmount(2400)->setCurrency('CNY')->setNotes('Private notes')
            ->setMetadata(['private' => 'private-order-metadata', 'counterUuid' => $this->counter->getUuid(), '_store' => ['uuid' => UUID::v4()]]);
        $this->order->addItem((new OrderItem())->setSpecificationUuid(UUID::v4())->setSpecificationTitle('Regular')
            ->setProductSnapshot(['name' => 'Lunch', 'private' => 'private-product-snapshot'])
            ->setSpecSnapshot(['private' => 'private-specification-snapshot'])->setMetadata(['private' => 'private-item-metadata'])
            ->setQuantity(2)->setUnitPrice(1200)->setPrice(2400)->setCost(500));
        $this->provider = new HuifuProviderProfile('Private provider', 'production', 'private-system-id', 'private-product-id');
        // Deliberately use a different encryption key: this read must not decrypt credentials.
        $this->provider->setCredentials(['merchant_private_key' => 'test-only-private-key', 'huifu_public_key' => 'test-only-public-key'], new HuifuSecretCipher('public-query-fixture-only'));
        $this->merchant = new HuifuMerchant('Private merchant', 'private-huifu-id', $this->provider, ['native']);
        $this->setting = new StoreHuifuSetting($this->store->getUuid(), $this->merchant);
        $this->setting->setAllowedMethods(['jspay', 'native']);
        $this->grant = new HuifuMerchantStoreGrant($this->merchant, $this->store->getUuid());
        $this->em->persist($this->store);
        $this->em->persist($this->counter);
        $this->em->flush();
        $this->em->wrapInTransaction(function () use ($payer): void {
            foreach ([$payer, $this->order, $this->provider, $this->merchant, $this->setting, $this->grant,
                new OrderStoreLifecycle($this->order->getUuid(), $this->store->getUuid())] as $entity) $this->em->persist($entity);
        });
    }

    public function testAnonymousReadProjectsOnlyPublicFieldsWithoutCreatingPayment(): void
    {
        $before = $this->paymentCounts();
        $response = $this->request();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('Authorization', $response->headers->get('Vary'));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['code' => 0, 'message' => 'SUCCESS', 'data' => [
            'orderUuid' => $this->order->getUuid(), 'status' => 'confirmed',
            'store' => ['uuid' => $this->store->getUuid(), 'name' => 'Public payment store'],
            'counter' => ['uuid' => $this->counter->getUuid(), 'name' => 'A01', 'code' => 'A01'],
            'currency' => 'CNY', 'amount' => 2400,
            'originalAmount' => 2400, 'discountAmount' => 0, 'discounts' => [],
            'items' => [['name' => 'Lunch', 'specification' => 'Regular', 'quantity' => 2, 'unitPrice' => 1200, 'amount' => 2400]],
            'provider' => 'huifu', 'channels' => ['native'], 'defaultChannel' => 'native', 'invoice' => null,
        ]], $body);
        self::assertSame(200, $this->request()->getStatusCode());
        self::assertSame($before, $this->paymentCounts());
        self::assertNull($this->order->getInvoiceId());
        self::assertSame('confirmed', $this->order->getStatus());
        self::assertStringNotContainsString('private-', $response->getContent());
        self::assertSame(200, $this->request(strtoupper($this->order->getUuid()))->getStatusCode());
    }

    public function testAnonymousReadProjectsOnlySafeDiscountSummary(): void
    {
        $this->order->setTotalAmount(2000)->setMetadata([
            'private' => 'private-order-metadata',
            'counterUuid' => $this->counter->getUuid(),
            '_promotion' => [
                'originalAmount' => 2400,
                'discountAmount' => 400,
                'applied' => [
                    ['id' => 1, 'name' => '会员优惠', 'type' => 'member_discount', 'phase' => 'inner', 'amount' => 300],
                    ['id' => 2, 'name' => '满减活动', 'type' => 'full_reduction', 'phase' => 'outer', 'amount' => 100],
                ],
                'private' => 'private-promotion-configuration',
            ],
        ]);
        $this->em->flush();

        $response = $this->request();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(2400, $data['originalAmount']);
        self::assertSame(400, $data['discountAmount']);
        self::assertSame([
            ['name' => '会员优惠', 'type' => 'member_discount', 'amount' => 300],
            ['name' => '满减活动', 'type' => 'full_reduction', 'amount' => 100],
        ], $data['discounts']);
        self::assertArrayNotHasKey('metadata', $data);
        self::assertStringNotContainsString('private-', $response->getContent());
    }

    public function testExistingInvoiceSummaryDoesNotExposePayerOrGatewayPayloads(): void
    {
        $invoice = $this->invoice()->setStatus(Invoice::STATUS_PAYING)->setPayment('huifu')
            ->setTransactionId('private-provider-transaction')->setDescription('private-invoice-description')
            ->setExtraData(['pay' => ['payInfo' => 'private-launch-data']]);
        $this->em->persist($invoice);
        $this->order->setInvoiceId($invoice->getUuid());
        $this->em->flush();
        $before = $this->paymentCounts();
        $response = $this->request();
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'uuid' => $invoice->getUuid(), 'outTradeNo' => $invoice->getOutTradeNo(), 'status' => 'paying',
            'amount' => 2400, 'currency' => 'CNY', 'payment' => 'huifu', 'refundedAmount' => 0, 'paidAt' => null,
        ], $body['data']['invoice']);
        self::assertStringNotContainsString('private-', $response->getContent());
        self::assertSame($before, $this->paymentCounts());
    }

    public function testOnlyCurrentInvoiceIsReturnedAndTerminalOrdersRemainReadable(): void
    {
        $old = $this->invoice()->setStatus(Invoice::STATUS_PAID);
        $current = $this->invoice()->setStatus(Invoice::STATUS_CANCELLED);
        $this->em->persist($old); $this->em->persist($current);
        $this->order->setInvoiceId($current->getUuid());
        foreach (['pending', 'confirmed', 'paid', 'completed', 'cancelled', 'merged', 'refunded'] as $status) {
            $this->order->setStatus($status); $this->em->flush();
            $response = $this->request();
            self::assertSame(200, $response->getStatusCode());
            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
            self::assertSame($status, $data['status']);
            self::assertSame($current->getUuid(), $data['invoice']['uuid']);
        }
    }

    public function testInvalidOrInvisibleOrdersReturnNotFound(): void
    {
        foreach ([UUID::v4(), '123', 'not-a-uuid'] as $uuid) self::assertSame(404, $this->request($uuid)->getStatusCode());
        $this->store->suspend(); $this->em->flush();
        self::assertSame(404, $this->request()->getStatusCode());
        $this->store->activate(); $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM trade_order_store_lifecycle WHERE trade_order_uuid = ?', [$this->order->getUuid()]);
        $this->em->clear();
        self::assertSame(404, $this->request()->getStatusCode());
    }

    public function testMissingOrForeignInvoiceAndAmountMismatchFailClosed(): void
    {
        $this->order->setInvoiceId(UUID::v4()); $this->em->flush();
        self::assertSame(409, $this->request()->getStatusCode());
        $invoice = $this->invoice(); $this->em->persist($invoice);
        $this->order->setInvoiceId($invoice->getUuid());
        foreach (['source' => UUID::v4(), 'type' => 'wallet_topup', 'scene' => 'deposit', 'amount' => 2500, 'currency' => 'USD'] as $field => $value) {
            if (!$this->em->contains($invoice)) $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $invoice->getUuid()]);
            $invoice->setSourceId($this->order->getUuid())->setSourceType('trade_order')->setScene('order')->setAmount(2400)->setCurrency('CNY');
            match ($field) {
                'source' => $invoice->setSourceId($value), 'type' => $invoice->setSourceType($value),
                'scene' => $invoice->setScene($value), 'amount' => $invoice->setAmount($value), 'currency' => $invoice->setCurrency($value),
            };
            $this->em->flush();
            $response = $this->request();
            self::assertSame(409, $response->getStatusCode());
            self::assertSame(['code' => 409, 'message' => 'order_payment_conflict'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function testChannelsRequireEnabledGrantedConfiguration(): void
    {
        foreach (['setting', 'merchant', 'provider'] as $configuration) {
            $this->$configuration->setEnabled(false); $this->em->flush();
            $this->assertNoChannels();
            $this->$configuration->setEnabled(true); $this->em->flush();
        }
        $this->merchant->setCapabilities(['jspay']); $this->em->flush();
        $data = json_decode($this->request()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['jspay'], $data['channels']);
        self::assertSame('jspay', $data['defaultChannel']);
        $this->em->remove($this->grant); $this->em->flush();
        $this->assertNoChannels();
        $this->em->remove($this->setting); $this->em->flush();
        $this->assertNoChannels();
    }

    public function testPublicEndpointDoesNotAcceptPaymentInitiation(): void
    {
        $before = $this->paymentCounts();
        self::assertGreaterThanOrEqual(400, $this->request(method: 'POST')->getStatusCode());
        self::assertSame($before, $this->paymentCounts());
    }

    public function testChannelsAreEmptyWithoutStoredCredentials(): void
    {
        $provider = new HuifuProviderProfile('Unconfigured provider', 'production', 'unconfigured-system', 'unconfigured-product');
        $this->em->persist($provider);
        $this->merchant->setProvider($provider); $this->em->flush();
        $this->assertNoChannels();
    }

    private function invoice(): Invoice
    {
        return (new Invoice())->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')
            ->setAmount(2400)->setCurrency('CNY')->setPayer($this->order->getUser());
    }

    private function request(?string $uuid = null, string $method = 'GET'): Response
    {
        $response = self::$kernel->handle(Request::create('/api/v1/public/orders/'.($uuid ?? $this->order->getUuid()).'/payment', $method));
        // Request-scoped services may clear Doctrine's identity map; subsequent
        // fixture mutations must target the currently managed instances.
        foreach (['store', 'order', 'provider', 'merchant', 'setting', 'grant'] as $property) {
            $entity = $this->$property;
            $current = $this->em->getRepository($entity::class)->findOneBy(['uuid' => $entity->getUuid()]);
            if ($current !== null) $this->$property = $current;
        }
        return $response;
    }

    private function assertNoChannels(): void
    {
        $response = $this->request();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame([], $data['channels']); self::assertNull($data['defaultChannel']);
    }

    private function paymentCounts(): array
    {
        $counts = [];
        foreach (['payment_invoice', 'huifu_payment_attempt', 'trade_outbox_message'] as $table) {
            $counts[$table] = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        }
        return $counts;
    }
}
