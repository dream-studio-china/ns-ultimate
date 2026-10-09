<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class PaymentApiIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    public function testManageAndAppInvoiceApiFlow(): void
    {
        $user = $this->currentUser();

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', [
            'sourceType' => 'manual',
            'sourceId' => 'source-api-1',
            'scene' => Invoice::SCENE_ORDER,
            'amount' => '12.34',
            'currency' => 'CNY',
            'payer' => $user->getId(),
            'subject' => 'API invoice',
            'description' => 'Created by test',
            'extraData' => ['foo' => 'bar'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(1234, $content['data']['amount']);
        $invoiceId = (int) $content['data']['id'];
        $outTradeNo = (string) $content['data']['outTradeNo'];

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/manage/invoices');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotEmpty($content['data']);

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/app/invoices/{$invoiceId}");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame($outTradeNo, $content['data']['outTradeNo']);

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/app/invoices/{$invoiceId}/pay/mock", ['autoPaid' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        [$response] = $this->jsonRequest('POST', '/api/payment/notify/mock', [
            'secret' => 'mock',
            'outTradeNo' => $outTradeNo,
            'amount' => 1234,
            'currency' => 'CNY',
            'transactionId' => 'duplicate-notify',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('SUCCESS', $response->getContent());

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/refund", [
            'amount' => 1234,
            'reason' => 'api refund',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(Invoice::STATUS_REFUNDED, $content['data']['status']);
        $this->em->clear();
        $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(Invoice::STATUS_REFUNDED, $invoice->getStatus());
    }

    public function testWebhookInvalidSecretReturnsFailure(): void
    {
        [$response] = $this->jsonRequest('POST', '/api/payment/notify/mock', ['secret' => 'bad']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringStartsWith('FAIL:', $response->getContent());
    }

    public function testManageCancelTransitionsAndAppNotFoundBranches(): void
    {
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', [
            'sourceType' => 'manual',
            'sourceId' => 'source-api-2',
            'scene' => Invoice::SCENE_DEPOSIT,
            'amount' => 500,
        ]);
        $invoiceId = (int) $content['data']['id'];

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/manage/invoices/{$invoiceId}/transitions");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotEmpty($content['data']);

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/cancel", ['reason' => 'api cancel']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(Invoice::STATUS_CANCELLED, $content['data']['status']);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/app/invoices/999/pay/mock', ['autoPaid' => true]);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    public function testManageValidationWarnings(): void
    {
        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', ['sourceType' => 'manual']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);

        [$response] = $this->jsonRequest('POST', '/api/v1/manage/invoices/999/pay/mock', []);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function jsonRequest(string $method, string $uri, array $data = []): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();
        return [$response, json_decode($response->getContent(), true) ?? []];
    }

    private function currentUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }
}
