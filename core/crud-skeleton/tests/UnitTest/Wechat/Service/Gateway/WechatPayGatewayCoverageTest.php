<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Service\Gateway;

use App\Payment\Entity\Invoice;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\Payment\WechatPayGateway;
use App\Wechat\Service\WechatServiceInterface;
use EasyWeChat\MiniApp\Account as MiniAccount;
use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\Pay\Application as PayApp;
use EasyWeChat\Pay\Merchant;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Covers the two defensive branches of WechatPayGateway::postJson():
 * a client without postJson() support and a client returning an invalid response.
 */
#[AllowMockObjectsWithoutExpectations]
final class WechatPayGatewayCoverageTest extends TestCase
{
    private WechatServiceInterface $wechatService;
    private WechatUserRepository $wechatUserRepo;
    private WechatPayGateway $gateway;

    protected function setUp(): void
    {
        $this->wechatService = $this->createMock(WechatServiceInterface::class);
        $this->wechatUserRepo = $this->createMock(WechatUserRepository::class);
        $psrHttpFactory = $this->createMock(HttpMessageFactoryInterface::class);

        $this->gateway = new WechatPayGateway(
            $this->wechatService,
            $this->wechatUserRepo,
            $psrHttpFactory,
            'https://example.com/notify/wechat',
        );
    }

    private function stubPayAppWithClient(object $client): void
    {
        $payApp = $this->createMock(PayApp::class);
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getMerchantId')->willReturn(1234567890);
        $payApp->method('getMerchant')->willReturn($merchant);
        $payApp->method('getClient')->willReturn($client);
        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $miniApp = $this->createMock(MiniApp::class);
        $miniAccount = $this->createMock(MiniAccount::class);
        $miniAccount->method('getAppId')->willReturn('wx_mini_app');
        $miniApp->method('getAccount')->willReturn($miniAccount);
        $this->wechatService->method('getMiniApp')->willReturn($miniApp);
    }

    private function createNativeInvoice(): Invoice
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('native');
        $invoice->method('getOutTradeNo')->willReturn('TXN_COVERAGE');
        $invoice->method('getSubject')->willReturn('Coverage order');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getCurrency')->willReturn('CNY');

        return $invoice;
    }

    #[Group('low-value')]
    public function testPayJsapiWithPayerButNoWechatUserThrows(): void
    {
        $payer = new \App\Identity\Entity\User();
        $this->wechatUserRepo->method('findByUser')->with($payer)->willReturn(null);

        $payApp = $this->createMock(PayApp::class);
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getMerchantId')->willReturn(1234567890);
        $payApp->method('getMerchant')->willReturn($merchant);
        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $miniApp = $this->createMock(MiniApp::class);
        $miniAccount = $this->createMock(MiniAccount::class);
        $miniAccount->method('getAppId')->willReturn('wx_mini_app');
        $miniApp->method('getAccount')->willReturn($miniAccount);
        $this->wechatService->method('getMiniApp')->willReturn($miniApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('jsapi');
        $invoice->method('getPayer')->willReturn($payer);
        $invoice->method('getOutTradeNo')->willReturn('TXN_JSAPI_NO_WX');
        $invoice->method('getSubject')->willReturn('JSAPI without wechat user');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getCurrency')->willReturn('CNY');

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('WeChat user not found for payer. Login via WeChat first.');

        $this->gateway->pay($invoice, 100);
    }

    private function createHttpClientWithoutPostJson(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new \LogicException('Not used in this test.');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \LogicException('Not used in this test.');
            }

            public function withOptions(array $options): static
            {
                throw new \LogicException('Not used in this test.');
            }
        };
    }

    private function createHttpClientReturningInvalidResponse(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new \LogicException('Not used in this test.');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \LogicException('Not used in this test.');
            }

            public function withOptions(array $options): static
            {
                throw new \LogicException('Not used in this test.');
            }

            public function postJson(string $url, array $data = [], array $options = []): string
            {
                return 'this-is-not-a-http-response';
            }
        };
    }

    public function testPayNativeClientWithoutPostJsonSupportThrows(): void
    {
        $this->stubPayAppWithClient($this->createHttpClientWithoutPostJson());

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('WeChat client does not support JSON requests.');

        $this->gateway->pay($this->createNativeInvoice(), 100);
    }

    public function testPayNativeClientReturningInvalidResponseThrows(): void
    {
        $this->stubPayAppWithClient($this->createHttpClientReturningInvalidResponse());

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('WeChat client returned an invalid response.');

        $this->gateway->pay($this->createNativeInvoice(), 100);
    }
}
