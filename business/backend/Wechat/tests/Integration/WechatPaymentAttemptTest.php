<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Core\Utils\UUID;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Service\InvoiceStoreContextAdapter;
use NsUltimate\Business\Wechat\Service\WechatPaymentAttemptService;

final class WechatPaymentAttemptTest extends WechatApiTestCase
{
    public function testTrustedStoreIsDerivedFromTheTradeLifecycle(): void
    {
        [$invoice, $store] = $this->invoiceForOrder();
        $adapter = self::getContainer()->get(InvoiceStoreContextAdapter::class);

        self::assertSame($store->getUuid(), $adapter->resolveStoreUuid($invoice));
    }

    public function testUnsupportedInvoiceSourceFailsClosed(): void
    {
        $invoice = new Invoice();
        $invoice->setSourceType('wallet_topup');
        $invoice->setSourceId(UUID::v4());
        $this->entityManager()->persist($invoice);
        $this->entityManager()->flush();

        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(InvoiceStoreContextAdapter::class)->resolveStoreUuid($invoice);
    }

    public function testAttemptIsIdempotentAndReusedPerInvoice(): void
    {
        [$invoice, $store] = $this->invoiceForOrder();
        $service = self::getContainer()->get(WechatPaymentAttemptService::class);

        $first = $service->start($invoice, $store->getUuid(), WechatPaymentAttempt::TRADE_NATIVE, $invoice->getAmount(), 'CNY', null, 'key-1');
        $second = $service->start($invoice, $store->getUuid(), WechatPaymentAttempt::TRADE_NATIVE, $invoice->getAmount(), 'CNY', null, 'key-1');
        self::assertSame($first->getUuid(), $second->getUuid());

        $third = $service->start($invoice, $store->getUuid(), WechatPaymentAttempt::TRADE_NATIVE, $invoice->getAmount(), 'CNY', null, 'key-2');
        self::assertSame($first->getUuid(), $third->getUuid());
        self::assertSame($invoice->getOutTradeNo(), $first->getProviderOrderNo());
    }

    public function testConflictingIdempotencyInputsAreRejected(): void
    {
        [$invoice, $store] = $this->invoiceForOrder();
        $service = self::getContainer()->get(WechatPaymentAttemptService::class);
        $service->start($invoice, $store->getUuid(), WechatPaymentAttempt::TRADE_NATIVE, $invoice->getAmount(), 'CNY', null, 'key-1');

        $this->expectException(\InvalidArgumentException::class);
        $service->start($invoice, $store->getUuid(), WechatPaymentAttempt::TRADE_NATIVE, $invoice->getAmount() + 1, 'CNY', null, 'key-1');
    }

    public function testCompletedAttemptCannotBeRestarted(): void
    {
        [$invoice, $store] = $this->invoiceForOrder();
        $service = self::getContainer()->get(WechatPaymentAttemptService::class);
        $attempt = $service->start($invoice, $store->getUuid(), WechatPaymentAttempt::TRADE_NATIVE, $invoice->getAmount(), 'CNY', null, 'key-1');
        $attempt->setStatus(WechatPaymentAttempt::STATUS_SUCCEEDED);
        $this->entityManager()->flush();

        $this->expectException(\InvalidArgumentException::class);
        $service->start($invoice, $store->getUuid(), WechatPaymentAttempt::TRADE_NATIVE, $invoice->getAmount(), 'CNY', null, 'key-2');
    }

    /** @return array{0: Invoice, 1: Store} */
    private function invoiceForOrder(): array
    {
        $store = $this->createStore('PAY');
        $order = new Order();
        $this->entityManager()->persist($order);
        $this->entityManager()->persist(new OrderStoreLifecycle($order->getUuid(), $store->getUuid()));
        $this->entityManager()->flush();

        $invoice = new Invoice();
        $invoice->setSourceType('trade_order');
        $invoice->setSourceId($order->getUuid());
        $invoice->setAmount(1000);
        $this->entityManager()->persist($invoice);
        $this->entityManager()->flush();

        return [$invoice, $store];
    }
}
