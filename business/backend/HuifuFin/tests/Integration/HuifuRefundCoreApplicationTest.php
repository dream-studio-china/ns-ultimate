<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Payment\Entity\InvoiceRefundAttempt;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HuifuRefundCoreApplicationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Order $order;
    private Invoice $invoice;
    private HuifuPaymentAttempt $attempt;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $client = $this->createMock(HuifuClientInterface::class);
        $client->expects(self::never())->method('post');
        self::getContainer()->set(HuifuClientInterface::class, $client);

        $payer = (new User())->setUsername('refund-payer')->setEmail('refund-payer@example.test');
        $store = new Store('refund-application', 'Refund application');
        $this->order = (new Order())->setUser($payer)->setStatus(Order::STATUS_PAID)->setTotalAmount(1000)->setCurrency('CNY');
        $this->invoice = (new Invoice())->setPayer($payer)->setStatus(Invoice::STATUS_PAID)->setAmount(1000)->setCurrency('CNY')
            ->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')->setPayment('huifu');
        $this->order->setInvoiceId($this->invoice->getUuid());
        $provider = new HuifuProviderProfile('Refund provider', 'production', 'test-system', 'test-product');
        $merchant = new HuifuMerchant('Refund merchant', 'test-merchant', $provider, ['native']);
        $this->attempt = new HuifuPaymentAttempt($this->invoice, $store->getUuid(), $provider->getUuid(), $merchant->getUuid(),
            $merchant->getHuifuId(), 1, '20261104', 'HF-REFUND-APPLICATION', 'native', 'T_NATIVE', 1000);
        $this->attempt->markSucceeded('test-payment-transaction');
        foreach ([$payer, $store, $provider, $merchant, $this->order, $this->invoice, $this->attempt] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }

    public function testConfirmedRefundUsesCoreTransactionsAndIsIdempotent(): void
    {
        $refund = $this->refund(400);
        $workflow = self::getContainer()->get(HuifuRefundWorkflow::class);
        $workflow->applyToCore($refund);
        $workflow->applyToCore($refund);
        $this->em->refresh($this->invoice);
        self::assertTrue($refund->isCoreApplied());
        self::assertSame(400, $this->invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $this->invoice->getStatus());
        self::assertSame(1, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testCommittedFullRefundCanRecoverAMissingLocalMarker(): void
    {
        $refund = $this->refund(1000);
        $events = 0;
        self::getContainer()->get('event_dispatcher')->addListener(InvoiceRefundedEvent::class, static function () use (&$events): void { ++$events; });
        $workflow = self::getContainer()->get(HuifuRefundWorkflow::class);
        $workflow->applyToCore($refund);
        self::assertSame(Order::STATUS_REFUNDED, $this->order->getStatus());
        // Simulate a crash after Core's commit but before the adapter marker commit.
        $this->em->getConnection()->executeStatement('UPDATE huifu_refund SET core_applied = 0 WHERE id = ?', [$refund->getId()]);
        $this->em->clear();
        $refund = $this->em->getRepository(HuifuRefund::class)->find($refund->getId());
        self::assertFalse($refund->isCoreApplied());
        $workflow->applyToCore($refund);
        self::assertTrue($refund->isCoreApplied());
        $invoice = $this->em->getRepository(Invoice::class)->find($this->invoice->getId());
        self::assertSame(1000, $invoice->getRefundedAmount());
        self::assertSame(1, $events);
        self::assertSame(1, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
    }

    public function testOuterTransactionIsRejectedWithoutRollingBackCaller(): void
    {
        $refund = $this->refund(400);
        $workflow = self::getContainer()->get(HuifuRefundWorkflow::class);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            foreach ([fn() => $workflow->applyToCore($refund), fn() => $workflow->request($this->invoice, 400, 'Nested'), fn() => $workflow->refresh($refund)] as $operation) {
                try {
                    $operation(); self::fail('An outer transaction must be rejected.');
                } catch (\LogicException $error) {
                    self::assertStringContainsString('outside an outer transaction', $error->getMessage());
                }
                self::assertTrue($connection->isTransactionActive());
            }
        } finally {
            $connection->rollBack();
        }
        self::assertSame(0, $this->invoice->getRefundedAmount());
        self::assertFalse($refund->isCoreApplied());
        self::assertSame(0, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
    }

    public function testInvalidSourceIsRejectedBeforeRequestingProviderRefund(): void
    {
        $this->order->setStatus(Order::STATUS_COMPLETED); $this->em->flush();
        try {
            self::getContainer()->get(HuifuRefundWorkflow::class)->request($this->invoice, 400, 'Invalid source');
            self::fail('An ineligible order must fail before the provider request.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('linked order must be paid', $error->getMessage());
        }
        self::assertSame(0, $this->em->getRepository(HuifuRefund::class)->count([]));
        self::assertSame(0, $this->invoice->getRefundedAmount());
    }

    private function refund(int $amount): HuifuRefund
    {
        $refund = new HuifuRefund($this->invoice, $this->attempt, 'HFR-APPLICATION-'.$amount, '20261104', $amount, $amount);
        $refund->markSucceeded($amount);
        $this->em->persist($refund); $this->em->flush();
        return $refund;
    }
}
