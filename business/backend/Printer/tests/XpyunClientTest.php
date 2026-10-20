<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Service\XpyunApiException;
use NsUltimate\Business\Printer\Service\XpyunClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class XpyunClientTest extends TestCase
{
    public function testLabelPrintUsesLabelEndpointAndSignedJsonRequest(): void
    {
        $captured = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR)];
            return new MockResponse('{"code":0,"data":"provider-order-1"}');
        });

        $response = (new XpyunClient($http))->print('developer@example.test', 'private-key', 'label', 'XPY123', '<PAGE/>', 2, 'idempotency-1');

        self::assertSame('provider-order-1', $response['data']);
        self::assertSame('POST', $captured[0]);
        self::assertSame('https://open.xpyun.net/api/openapi/xprinter/printLabel', $captured[1]);
        self::assertSame('developer@example.test', $captured[2]['user']);
        self::assertSame('XPY123', $captured[2]['sn']);
        self::assertSame(2, $captured[2]['copies']);
        self::assertSame('idempotency-1', $captured[2]['idempotent']);
        self::assertSame(sha1($captured[2]['user'].'private-key'.$captured[2]['timestamp']), $captured[2]['sign']);
    }

    public function testReceiptPrintUsesSeparateEndpoint(): void
    {
        $url = null;
        $http = new MockHttpClient(static function (string $method, string $requestUrl) use (&$url): MockResponse {
            $url = $requestUrl;
            return new MockResponse('{"code":0,"data":"receipt-order"}');
        });

        (new XpyunClient($http))->print('developer', 'key', 'receipt', 'XPY123', 'receipt');
        self::assertSame('https://open.xpyun.net/api/openapi/xprinter/print', $url);
    }

    public function testProviderErrorCodeIsPreservedWithoutExposingPayload(): void
    {
        $http = new MockHttpClient(new MockResponse('{"code":1003,"msg":"PRINTER_OFFLINE","data":false}'));
        try {
            (new XpyunClient($http))->print('developer', 'key', 'receipt', 'XPY123', 'receipt');
            self::fail('Expected provider rejection.');
        } catch (XpyunApiException $exception) {
            self::assertSame('1003', $exception->providerCode);
            self::assertStringNotContainsString('private-key', $exception->getMessage());
        }
    }
}
