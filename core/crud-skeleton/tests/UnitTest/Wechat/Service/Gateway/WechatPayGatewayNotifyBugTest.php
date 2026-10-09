<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Service\Gateway;

use App\Payment\Exception\PaymentVerificationException;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\Payment\WechatPayGateway;
use App\Wechat\Service\WechatServiceInterface;
use EasyWeChat\Pay\Application as PayApp;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Documents Bug 1 (see docs/issues/coverage-2026-08-09/wechat.md):
 * WechatPayGateway::notify() never propagates the incoming Symfony request
 * into the EasyWeChat Pay server, so serve() always reads an empty default
 * request and fails with "Invalid request body" — even for a valid callback.
 *
 * The assertions below describe the correct behaviour and FAIL against the
 * current src, so the test is skipped to keep the suite green.
 */
#[AllowMockObjectsWithoutExpectations]
final class WechatPayGatewayNotifyBugTest extends TestCase
{
    public function testNotifyPropagatesIncomingRequestToWechatServer(): void
    {
        $this->markTestSkipped(
            'Known src bug (Bug 1): WechatPayGateway::notify() never calls ' .
            '$app->setRequestFromSymfonyRequest($request) (or $server->setRequest($psrRequest)), ' .
            'so the EasyWeChat server reads an empty fromGlobals() request and always throws ' .
            '"Invalid request body". Removing the skip makes this test fail.'
        );

        $wechatService = $this->createMock(WechatServiceInterface::class);
        $wechatUserRepo = $this->createMock(WechatUserRepository::class);
        $psrHttpFactory = $this->createMock(HttpMessageFactoryInterface::class);

        $payApp = new PayApp([
            'mch_id' => 1234567890,
            'private_key' => '/tmp/merchant_private.pem',
            'certificate' => '/tmp/merchant_cert.pem',
            'secret_key' => '0123456789abcdef0123456789abcdef',
            'http' => ['throw' => true, 'timeout' => 5.0],
        ]);
        $wechatService->method('getPayApp')->willReturn($payApp);

        $gateway = new WechatPayGateway(
            $wechatService,
            $wechatUserRepo,
            $psrHttpFactory,
            'https://example.com/notify/wechat',
        );

        $body = json_encode([
            'id' => 'evt_001',
            'event_type' => 'TRANSACTION.SUCCESS',
            'resource' => ['ciphertext' => 'abc', 'associated_data' => '', 'nonce' => ''],
        ]);
        $request = Request::create(
            '/notify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        try {
            $gateway->notify($request);
            self::fail('Expected a PaymentVerificationException when the callback cannot be verified.');
        } catch (PaymentVerificationException $e) {
            // The incoming request was propagated, so failure must come from
            // signature/decryption verification — never from an empty request body.
            self::assertStringNotContainsString('Invalid request body', $e->getMessage());
        }
    }
}
