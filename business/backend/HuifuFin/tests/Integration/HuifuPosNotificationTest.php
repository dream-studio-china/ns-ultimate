<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\InvoiceServiceInterface;
use App\Store\Entity\Store;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPosDevice;
use NsUltimate\Business\HuifuFin\Entity\HuifuPosReceipt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Service\Pos\HuifuPosNotificationService;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HuifuPosNotificationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ($entityManager->getConnection()->createSchemaManager()->listTableNames() !== []) {
            $schemaTool->dropSchema($metadata);
        }
        $schemaTool->createSchema($metadata);
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testPosReceiptLinksOnlyToTheRegisteredStoreAndIsIdempotent(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $cipher = self::getContainer()->get(HuifuSecretCipher::class);
        $user = (new User())->setEmail('huifu-pos@example.test')->setUsername('huifu-pos');
        $store = new Store('huifu-pos-store', 'Huifu POS Store');
        $em->persist($user);
        $em->persist($store);
        $em->flush();
        $provider = new HuifuProviderProfile('POS provider', 'production', '6666000000000001', 'YMFZS');
        $provider->setCredentials([
            'merchant_private_key' => $privateKey,
            'huifu_public_key' => $publicKey,
            'async_public_key' => $publicKey,
        ], $cipher);
        $merchant = new HuifuMerchant('POS merchant', '6666000000000101', $provider);
        $setting = new StoreHuifuSetting($store->getUuid(), $merchant);
        $setting->setPosEnabled(true);
        $device = new HuifuPosDevice($merchant, $store->getUuid(), 'TERMINAL-1');
        $device->setMode(HuifuPosDevice::MODE_LINKED_ORDER);
        foreach ([$provider, $merchant, $setting, $device] as $entity) {
            $em->persist($entity);
        }
        $orderUuid = 'order-huifu-pos';
        $em->persist(new OrderStoreLifecycle($orderUuid, $store->getUuid()));
        $em->flush();

        $invoiceService = self::getContainer()->get(InvoiceServiceInterface::class);
        $invoice = $invoiceService->createInvoice(new CreateInvoiceRequest(
            sourceType: 'trade_order',
            sourceId: $orderUuid,
            scene: Invoice::SCENE_ORDER,
            amount: 2500,
            currency: 'CNY',
            payer: $user,
            subject: 'POS order',
        ));
        $respData = json_encode([
            'resp_code' => '00000000',
            'resp_desc' => '交易成功',
            'req_seq_id' => $invoice->getOutTradeNo(),
            'req_date' => '20261101',
            'huifu_id' => $merchant->getHuifuId(),
            'trans_amt' => '25.00',
            'trans_stat' => 'S',
            'out_trans_id' => 'POS-TRANSACTION-1',
            'devs_id' => 'TERMINAL-1',
            'end_time' => '20261101120000',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/api/payment/notify/huifu-pos/'.$merchant->getUuid(),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'resp_code' => '00000000',
                'resp_data' => $respData,
                'sign' => $this->signRaw($respData, $privateKey),
            ]),
        );
        $service = new HuifuPosNotificationService(
            $em,
            self::getContainer()->get(HuifuProfileResolver::class),
            self::getContainer()->get(HuifuSigner::class),
            self::getContainer()->get(HuifuResponseParser::class),
            $invoiceService,
        );
        self::assertSame('RECV_ORD_ID_'.$invoice->getOutTradeNo(), $service->handle($request, $merchant->getUuid()));
        $em->refresh($invoice);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame('huifu-pos', $invoice->getPayment());
        $receipt = $em->getRepository(HuifuPosReceipt::class)->findOneBy(['providerTransactionId' => 'POS-TRANSACTION-1']);
        self::assertInstanceOf(HuifuPosReceipt::class, $receipt);
        self::assertSame(HuifuPosReceipt::STATUS_LINKED, $receipt->getStatus());
        self::assertSame($invoice->getUuid(), $receipt->getInvoiceUuid());

        self::assertSame('RECV_ORD_ID_'.$invoice->getOutTradeNo(), $service->handle($request, $merchant->getUuid()));
        self::assertSame(1, $em->getRepository(HuifuPosReceipt::class)->count([]));

        $unknownData = json_decode($respData, true, flags: JSON_THROW_ON_ERROR);
        $unknownData['devs_id'] = 'UNKNOWN-TERMINAL';
        $unknownData['out_trans_id'] = 'POS-TRANSACTION-2';
        $unknownBody = json_encode($unknownData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $unknownRequest = Request::create(
            '/api/payment/notify/huifu-pos/'.$merchant->getUuid(),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'resp_code' => '00000000',
                'resp_data' => $unknownBody,
                'sign' => $this->signRaw($unknownBody, $privateKey),
            ]),
        );
        $this->expectException(PaymentVerificationException::class);
        $service->handle($unknownRequest, $merchant->getUuid());
    }

    /** @return array{0: string, 1: string} */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        self::assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        return [$privateKey, $details['key']];
    }

    private function signRaw(string $data, string $privateKey): string
    {
        $resource = openssl_pkey_get_private($privateKey);
        self::assertNotFalse($resource);
        $signature = '';
        self::assertTrue(openssl_sign($data, $signature, $resource, OPENSSL_ALGO_SHA256));

        return base64_encode($signature);
    }
}
