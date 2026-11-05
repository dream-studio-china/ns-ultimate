<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Payment\Entity\InvoiceRefundAttempt;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Command\ReconcileHuifuPaymentsCommand;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;
use NsUltimate\Business\HuifuFin\Tests\Support\FakeHuifuClient;
use NsUltimate\Integration\Backend\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/../Support/FakeHuifuClient.php';

final class HuifuRefundConfirmationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Invoice $invoice;
    private HuifuPaymentAttempt $attempt;
    private Order $order;
    private FakeHuifuClient $client;
    private string $publicKey;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $this->publicKey = $details['key'];
        $provider = new HuifuProviderProfile('Confirmation provider', 'production', 'test-system', 'test-product');
        $provider->setCredentials(['merchant_private_key' => $privateKey, 'huifu_public_key' => $details['key']], self::getContainer()->get(HuifuSecretCipher::class));
        $merchant = new HuifuMerchant('Confirmation merchant', 'test-merchant', $provider, ['native']);
        $payer = (new User())->setUsername('confirmation-payer')->setEmail('confirmation@example.test');
        $store = new Store('confirmation-store', 'Confirmation store');
        $this->order = (new Order())->setUser($payer)->setStatus(Order::STATUS_PAID)->setTotalAmount(1000)->setCurrency('CNY');
        $this->invoice = (new Invoice())->setPayer($payer)->setStatus(Invoice::STATUS_PAID)->setAmount(1000)->setCurrency('CNY')
            ->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order')->setPayment('huifu');
        $this->order->setInvoiceId($this->invoice->getUuid());
        $this->attempt = new HuifuPaymentAttempt($this->invoice, $store->getUuid(), $provider->getUuid(), $merchant->getUuid(),
            $merchant->getHuifuId(), $provider->getCredentialVersion(), '20261001', 'PAYMENT-SEQUENCE', 'native', 'T_NATIVE', 1000);
        $this->attempt->markSucceeded('payment-transaction');
        foreach ([$payer, $store, $provider, $merchant, $this->order, $this->invoice, $this->attempt] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->client = new FakeHuifuClient($privateKey);
        self::getContainer()->set(HuifuClientInterface::class, $this->client);
    }

    public function testUnknownRefundQueriesRefundIdentityAndAppliesOnlyOnce(): void
    {
        $refund = $this->existingRefund();
        $this->client->responses[] = ['data' => $this->response($refund)];
        $workflow = self::getContainer()->get(HuifuRefundWorkflow::class);
        $workflow->refresh($refund);
        $workflow->refresh($refund);
        self::assertCount(1, $this->client->requests);
        $request = $this->client->requests[0];
        self::assertSame(HuifuEndpoints::SCANPAY_REFUND_QUERY, $request['path']);
        self::assertSame(['huifu_id' => 'test-merchant', 'org_req_date' => '20261105', 'org_req_seq_id' => 'REFUND-SEQUENCE'], $request['payload']['data']);
        self::assertTrue((new HuifuSigner())->verifyData($request['payload']['data'], $request['payload']['sign'], $this->publicKey));
        self::assertTrue($refund->isCoreApplied());
        self::assertSame(400, $this->invoice->getRefundedAmount());
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $this->invoice->getStatus());
        self::assertSame(Order::STATUS_PAID, $this->order->getStatus());
        self::assertSame(1, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        self::assertSame('00000000', $refund->getResponseCode());
        self::assertSame('Confirmed refund', $refund->getResponseMessage());
        self::assertSame(1, $refund->getQueryCount());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unsafeResponses(): iterable
    {
        yield 'payment sequence' => [['req_seq_id' => 'PAYMENT-SEQUENCE'], 'identity'];
        yield 'payment date' => [['req_date' => '20261001'], 'identity'];
        yield 'wrong merchant' => [['huifu_id' => 'another-merchant'], 'identity'];
        yield 'wrong amount' => [['refund_amt' => '10.00'], 'amount'];
        yield 'malformed amount' => [['refund_amt' => []], 'amount'];
        yield 'query rejection is not refund failure' => [['resp_code' => 'ERROR', 'resp_desc' => 'Refund not found', 'trans_stat' => 'F'], 'Refund not found'];
        yield 'missing status' => [['trans_stat' => null], 'recognized transaction status'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('unsafeResponses')]
    public function testUnsafeConfirmationStaysUnknown(array $overrides, string $message): void
    {
        $refund = $this->existingRefund();
        $this->client->responses[] = ['data' => array_replace($this->response($refund), $overrides)];
        self::getContainer()->get(HuifuRefundWorkflow::class)->refresh($refund);
        self::assertSame(HuifuRefund::STATUS_UNKNOWN, $refund->getStatus());
        self::assertStringContainsString($message, $refund->getResponseMessage());
        self::assertNotNull($refund->getNextQueryAt());
        $this->assertNotApplied($refund);
    }

    public function testQueryVerificationFailureIsRecordedAndCanRecover(): void
    {
        $refund = $this->existingRefund();
        $this->client->responses[] = ['resp_code' => '00000000'];
        $workflow = self::getContainer()->get(HuifuRefundWorkflow::class);
        try {
            $workflow->refresh($refund);
            self::fail('An unsigned query must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not signed', $error->getMessage());
        }
        $this->em->refresh($refund);
        self::assertSame(1, $refund->getQueryCount());
        self::assertStringContainsString('could not be verified', $refund->getResponseMessage());
        self::assertNull($refund->getResponseSnapshot());
        $this->assertNotApplied($refund);
        $this->client->responses[] = ['data' => $this->response($refund)];
        $workflow->refresh($refund);
        self::assertTrue($refund->isCoreApplied());
        self::assertSame(2, $refund->getQueryCount());
    }

    public function testSubmissionTimeoutRecoversByQueryWithoutResubmission(): void
    {
        $this->client->responses[] = new \RuntimeException('Simulated timeout');
        $workflow = self::getContainer()->get(HuifuRefundWorkflow::class);
        try {
            $workflow->request($this->invoice, 400, 'Timeout recovery');
            self::fail('Submission must time out.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulated timeout', $error->getMessage());
        }
        $refund = $this->em->getRepository(HuifuRefund::class)->findOneBy([]);
        self::assertInstanceOf(HuifuRefund::class, $refund);
        self::assertSame(HuifuRefund::STATUS_UNKNOWN, $refund->getStatus());
        $this->client->responses[] = ['data' => $this->response($refund)];
        $workflow->refresh($refund);
        $workflow->refresh($refund);
        self::assertSame([HuifuEndpoints::SCANPAY_REFUND, HuifuEndpoints::SCANPAY_REFUND_QUERY], array_column($this->client->requests, 'path'));
        self::assertSame(400, $this->invoice->getRefundedAmount());
    }

    public function testProcessingQueryCanLaterConfirmFullRefund(): void
    {
        $refund = $this->existingRefund(1000);
        $this->client->responses[] = ['data' => array_replace($this->response($refund), ['trans_stat' => 'P'])];
        $this->client->responses[] = ['data' => $this->response($refund)];
        $workflow = self::getContainer()->get(HuifuRefundWorkflow::class);
        $workflow->refresh($refund);
        self::assertSame(HuifuRefund::STATUS_PROCESSING, $refund->getStatus());
        $this->assertNotApplied($refund);
        $workflow->refresh($refund);
        self::assertSame(1000, $this->invoice->getRefundedAmount());
        self::assertSame(Order::STATUS_REFUNDED, $this->order->getStatus());
    }

    public function testAcceptedSubmissionImmediatelyQueriesAndConfirms(): void
    {
        $number = 'SOR'.str_repeat('c', 32);
        $data = ['resp_code' => '00000100', 'req_seq_id' => $number, 'req_date' => date('Ymd'), 'huifu_id' => 'test-merchant', 'refund_amt' => '4.00'];
        $this->client->responses[] = ['data' => $data + ['trans_stat' => 'P']];
        $this->client->responses[] = ['data' => $data + ['trans_stat' => 'S']];
        $refund = self::getContainer()->get(HuifuRefundWorkflow::class)->request($this->invoice, 400, 'Immediate confirmation', ['refundNo' => $number]);
        self::assertTrue($refund->isCoreApplied());
        self::assertSame(400, $this->invoice->getRefundedAmount());
        self::assertSame($number, $this->client->requests[1]['payload']['data']['org_req_seq_id']);
    }

    public function testSynchronousFailureDoesNotQueryOrApply(): void
    {
        $this->client->responses[] = ['data' => ['resp_code' => '00000000', 'resp_desc' => 'Refund failed', 'trans_stat' => 'F']];
        $refund = self::getContainer()->get(HuifuRefundWorkflow::class)->request($this->invoice, 400, 'Rejected refund');
        self::assertSame(HuifuRefund::STATUS_FAILED, $refund->getStatus());
        self::assertCount(1, $this->client->requests);
        $this->assertNotApplied($refund);
    }

    public function testSynchronousConflictingResponseCannotApply(): void
    {
        $this->client->responses[] = ['data' => ['resp_code' => '00000000', 'trans_stat' => 'S', 'refund_amt' => '10.00']];
        try {
            self::getContainer()->get(HuifuRefundWorkflow::class)->request($this->invoice, 400, 'Conflicting refund');
            self::fail('A mismatched refund amount must not be applied.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('amount does not match', $error->getMessage());
        }
        $refund = $this->em->getRepository(HuifuRefund::class)->findOneBy([]);
        self::assertInstanceOf(HuifuRefund::class, $refund);
        self::assertSame(HuifuRefund::STATUS_UNKNOWN, $refund->getStatus());
        $this->assertNotApplied($refund);
    }

    public function testQueryTransportFailurePersistsDiagnosticAndRetrySchedule(): void
    {
        $refund = $this->existingRefund();
        $this->client->responses[] = new \RuntimeException('Simulated query timeout');
        try {
            self::getContainer()->get(HuifuRefundWorkflow::class)->refresh($refund);
            self::fail('The query must time out.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulated query timeout', $error->getMessage());
        }
        $this->em->refresh($refund);
        self::assertSame(1, $refund->getQueryCount());
        self::assertNotNull($refund->getLastQueriedAt());
        self::assertNotNull($refund->getNextQueryAt());
        self::assertStringContainsString('did not return a usable response', $refund->getResponseMessage());
        $this->assertNotApplied($refund);
    }

    public function testTargetedRecoveryQueriesOnlyTheSelectedRefundAndIsIdempotent(): void
    {
        $refund = $this->existingRefund();
        $this->client->responses[] = ['data' => $this->response($refund)];
        $command = new CommandTester(self::getContainer()->get(ReconcileHuifuPaymentsCommand::class));
        self::assertSame(0, $command->execute(['--refund' => $refund->getUuid()]));
        self::assertStringContainsString('coreApplied=yes', $command->getDisplay());
        self::assertSame(0, $command->execute(['--refund' => $refund->getUuid()]));
        self::assertCount(1, $this->client->requests);
        self::assertSame(HuifuEndpoints::SCANPAY_REFUND_QUERY, $this->client->requests[0]['path']);
        self::assertSame(1, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
        self::assertSame(2, $command->execute(['--refund' => 'invalid']));
        self::assertSame(2, $command->execute(['--refund' => '00000000-0000-4000-8000-000000000001']));
        self::assertCount(1, $this->client->requests);
    }

    public function testTargetedRecoveryDoesNotReportAnUnconfirmedRefundAsSuccess(): void
    {
        $refund = $this->existingRefund();
        $this->client->responses[] = ['data' => array_replace($this->response($refund), ['trans_stat' => 'P'])];
        $command = new CommandTester(self::getContainer()->get(ReconcileHuifuPaymentsCommand::class));
        self::assertSame(1, $command->execute(['--refund' => $refund->getUuid()]));
        self::assertStringContainsString('coreApplied=no', $command->getDisplay());
        $this->assertNotApplied($refund);
    }

    private function existingRefund(int $amount = 400): HuifuRefund
    {
        $refund = new HuifuRefund($this->invoice, $this->attempt, 'REFUND-SEQUENCE', '20261105', $amount, $amount);
        $refund->markUnknown();
        $this->em->persist($refund);
        $this->em->flush();
        return $refund;
    }

    /** @return array<string, mixed> */
    private function response(HuifuRefund $refund): array
    {
        return ['resp_code' => '00000000', 'resp_desc' => 'Confirmed refund', 'trans_stat' => 'S',
            'req_date' => $refund->getReqDate(), 'req_seq_id' => $refund->getRefundNo(), 'huifu_id' => 'test-merchant',
            'refund_amt' => number_format($refund->getProviderAmount() / 100, 2, '.', '')];
    }

    private function assertNotApplied(HuifuRefund $refund): void
    {
        self::assertFalse($refund->isCoreApplied());
        self::assertSame(0, $this->invoice->getRefundedAmount());
        self::assertSame(0, $this->em->getRepository(InvoiceRefundAttempt::class)->count([]));
    }
}
