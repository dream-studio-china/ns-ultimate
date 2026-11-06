<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Tests\Support\FakeHuifuClient;
use NsUltimate\Business\Store\Service\OrderPaymentCloseService;
use NsUltimate\Integration\Backend\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/FakeHuifuClient.php';

final class HuifuPaymentCloseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Store $store;
    private User $user;
    private Order $order;
    private Invoice $invoice;
    private HuifuPaymentAttempt $attempt;
    private FakeHuifuClient $client;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $provider = new HuifuProviderProfile('Close provider', 'production', 'test-system', 'test-product');
        $provider->setCredentials(['merchant_private_key' => $privateKey, 'huifu_public_key' => $details['key']], self::getContainer()->get(HuifuSecretCipher::class));
        $merchant = new HuifuMerchant('Close merchant', 'test-merchant', $provider, ['native']);
        $this->user = (new User())->setUsername('close-payer')->setEmail('close-payer@example.test');
        $this->store = new Store('huifu-close-store', 'Huifu close store');
        $this->order = (new Order())->setUser($this->user)->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1000)->setCurrency('CNY');
        $this->invoice = (new Invoice())->setPayer($this->user)->setStatus(Invoice::STATUS_PAYING)->setAmount(1000)->setCurrency('CNY')
            ->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')->setPayment('huifu');
        $this->order->setInvoiceId($this->invoice->getUuid())->setPaymentStatus('pending');
        $this->attempt = new HuifuPaymentAttempt($this->invoice, $this->store->getUuid(), $provider->getUuid(), $merchant->getUuid(),
            $merchant->getHuifuId(), $provider->getCredentialVersion(), '20261104', 'ORIGINAL-PAYMENT', 'native', 'T_NATIVE', 1000);
        $this->attempt->markPending();
        foreach ([$this->user, $this->store, $provider, $merchant, $this->order, $this->invoice, $this->attempt,
            new OrderStoreLifecycle($this->order->getUuid(), $this->store->getUuid())] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->client = new FakeHuifuClient($privateKey);
        self::getContainer()->set(HuifuClientInterface::class, $this->client);
    }

    public function testPayingInvoiceClosesOnlyAfterVerifiedTerminalPaymentQuery(): void
    {
        $this->client->responses = [$this->payment('P'), ['data' => ['resp_code' => '00000100', 'trans_stat' => 'P']], $this->payment('F')];
        $result = $this->close();
        self::assertTrue($result['released']);
        self::assertSame(Invoice::STATUS_CANCELLED, $this->invoice->getStatus());
        self::assertNull($this->order->getInvoiceId());
        self::assertSame(Order::STATUS_CONFIRMED, $this->order->getStatus());
        self::assertSame(HuifuPaymentAttempt::STATUS_CLOSED, $this->attempt->getStatus());
        self::assertSame([HuifuEndpoints::SCANPAY_QUERY, HuifuEndpoints::SCANPAY_CLOSE, HuifuEndpoints::SCANPAY_QUERY], array_column($this->client->requests, 'path'));
        $request = $this->client->requests[1]['payload']['data'];
        self::assertSame('20261104', $request['org_req_date']);
        self::assertSame('ORIGINAL-PAYMENT', $request['org_req_seq_id']);
        self::assertNotSame($request['org_req_seq_id'], $request['req_seq_id']);
    }

    public function testPreviouslyFailedAttemptCanReleaseStuckPayingInvoiceAfterFreshConfirmation(): void
    {
        $this->attempt->markFailed(); $this->em->flush();
        $this->client->responses = [$this->payment('F')];
        self::assertTrue($this->close()['released']);
        self::assertCount(1, $this->client->requests);
        self::assertSame(HuifuEndpoints::SCANPAY_QUERY, $this->client->requests[0]['path']);
        self::assertSame(Invoice::STATUS_CANCELLED, $this->invoice->getStatus());
    }

    public function testPaymentSuccessDuringCloseIsAppliedRatherThanReleased(): void
    {
        $this->client->responses = [$this->payment('P'), ['data' => ['resp_code' => '00000000', 'trans_stat' => 'S']], $this->payment('S')];
        $result = $this->close();
        self::assertFalse($result['released']);
        self::assertSame('paid', $result['status']);
        self::assertSame(Order::STATUS_PAID, $this->order->getStatus());
        self::assertSame(Invoice::STATUS_PAID, $this->invoice->getStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvedCases(): iterable
    {
        foreach (['close-rejected', 'still-processing', 'timeout', 'unsigned', 'amount-mismatch', 'identity-mismatch', 'query-rejected', 'missing-attempt', 'quickpay'] as $case) yield $case => [$case];
    }

    #[DataProvider('unresolvedCases')]
    public function testUnconfirmedOrUnsupportedOutcomeNeverReleases(string $case): void
    {
        $this->client->responses = [$this->payment('P')];
        switch ($case) {
            case 'close-rejected': $this->client->responses[] = ['data' => ['resp_code' => 'ERROR']]; break;
            case 'still-processing':
                $this->client->responses[] = ['data' => ['resp_code' => '00000100', 'trans_stat' => 'S']];
                $this->client->responses[] = $this->payment('P'); break;
            case 'timeout': $this->client->responses[] = new \RuntimeException('Simulated close timeout'); break;
            case 'unsigned': $this->client->responses = [['resp_code' => '00000000']]; break;
            case 'amount-mismatch': $this->client->responses = [$this->payment('F', ['trans_amt' => '1.00'])]; break;
            case 'identity-mismatch': $this->client->responses = [$this->payment('F', ['req_seq_id' => 'OTHER-PAYMENT'])]; break;
            case 'query-rejected': $this->client->responses = [$this->payment('F', ['resp_code' => 'ERROR'])]; break;
            case 'missing-attempt': $this->em->remove($this->attempt); $this->em->flush(); break;
            case 'quickpay':
                $this->em->remove($this->attempt); $this->em->flush();
                $attempt = new HuifuPaymentAttempt($this->invoice, $this->store->getUuid(), '', '', '', 1, '20261104', 'QUICKPAY', 'quickpay');
                $this->em->persist($attempt); $this->em->flush(); break;
        }
        try {
            $this->close(); self::fail('An unconfirmed payment must remain held.');
        } catch (HttpException|\RuntimeException) {
            $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $this->order->getUuid()]);
            $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $this->invoice->getUuid()]);
            self::assertSame($invoice->getUuid(), $order->getInvoiceId());
            self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());
            self::assertSame('pending', $order->getPaymentStatus());
        }
    }

    public function testEveryAttemptMustBeResolvedBeforeRelease(): void
    {
        $second = new HuifuPaymentAttempt($this->invoice, $this->store->getUuid(), $this->attempt->getProviderProfileUuid(), $this->attempt->getMerchantUuid(),
            'test-merchant', $this->attempt->getCredentialVersion(), '20261104', 'SECOND-PAYMENT', 'native', 'T_NATIVE', 1000);
        $second->markPending(); $this->em->persist($second); $this->em->flush();
        $this->client->responses = [$this->payment('F'), $this->payment('P', ['req_seq_id' => 'SECOND-PAYMENT']), ['data' => ['resp_code' => 'ERROR']]];
        try {
            $this->close(); self::fail('One failed attempt does not prove the entire Invoice closed.');
        } catch (HttpException) {
            self::assertSame($this->invoice->getUuid(), $this->em->getRepository(Order::class)->findOneBy(['uuid' => $this->order->getUuid()])->getInvoiceId());
        }
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function payment(string $status, array $overrides = []): array
    {
        return ['data' => array_replace(['resp_code' => '00000000', 'trans_stat' => $status, 'huifu_id' => 'test-merchant',
            'req_date' => '20261104', 'req_seq_id' => 'ORIGINAL-PAYMENT', 'trans_amt' => '10.00', 'out_trans_id' => 'collection-transaction'], $overrides)];
    }

    /** @return array<string, mixed> */
    private function close(): array
    {
        return self::getContainer()->get(OrderPaymentCloseService::class)->close($this->store, $this->order->getUuid(), $this->user->getUuid(),
            ['commandUuid' => UUID::v4(), 'invoiceUuid' => $this->invoice->getUuid(), 'reason' => 'Close payment for merging']);
    }
}
