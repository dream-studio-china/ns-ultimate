<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrinterCredentialCipher;
use NsUltimate\Business\Printer\Service\PrinterProviderException;
use NsUltimate\Business\Printer\Service\XpyunClient;
use NsUltimate\Business\Printer\Service\XpyunPrinterProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class XpyunPrinterProviderCredentialsTest extends TestCase
{
    public function testMissingCredentialsFailTheJobWithoutCallingTheProvider(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests) {
            ++$requests;
            self::fail('The provider must not be contacted without credentials.');
        });
        $cipher = new PrinterCredentialCipher('test-only-key');
        $account = new PrinterAccount('Offline demo', 'local-printer-demo', '');
        $device = new PrinterDevice($account, 'Demo', 'SERIAL', PrinterDevice::KIND_RECEIPT);
        $job = new PrinterJob($device, 1, ['kind' => 'receipt', 'nodes' => []], [], '<TEXT>demo</TEXT>', 'credential-test');
        $provider = new XpyunPrinterProvider($cipher, new XpyunClient($client));

        try {
            $provider->submitJob($job);
            self::fail('Missing credentials were accepted.');
        } catch (PrinterProviderException $exception) {
            self::assertSame('missing_credentials', $exception->providerCode);
        }
        self::assertSame(0, $requests);
    }

    public function testRegistrationFailureReportsBoundedProviderReason(): void
    {
        $cipher = new PrinterCredentialCipher('test-only-key');
        $account = new PrinterAccount('Account', 'developer@example.test', $cipher->encrypt('private-key'));
        $device = new PrinterDevice($account, 'Receipt', '74NTC9HBWZFF44A', PrinterDevice::KIND_RECEIPT);
        $http = new MockHttpClient(new \Symfony\Component\HttpClient\Response\MockResponse(
            '{"code":0,"data":{"success":[],"fail":["74NTC9HBWZFF44A"],"failMsg":["74NTC9HBWZFF44A:1010"]}}'
        ));
        $provider = new XpyunPrinterProvider($cipher, new XpyunClient($http));

        try {
            $provider->registerDevice($account, $device);
            self::fail('Registration failure was accepted.');
        } catch (PrinterProviderException $exception) {
            self::assertSame('registration_unconfirmed', $exception->providerCode);
            self::assertStringContainsString('printer number is invalid', $exception->getMessage());
            self::assertStringNotContainsString('private-key', $exception->getMessage());
        }
    }

    public function testAlreadyRegisteredPrinterIsAcceptedAfterStatusConfirmation(): void
    {
        $cipher = new PrinterCredentialCipher('test-only-key');
        $account = new PrinterAccount('Account', 'developer@example.test', $cipher->encrypt('private-key'));
        $device = new PrinterDevice($account, 'Label', '32Z7NQJ70YC7549', PrinterDevice::KIND_LABEL, null, null, 40, 30);
        $http = new MockHttpClient(static function (string $method, string $url): \Symfony\Component\HttpClient\Response\MockResponse {
            return str_contains($url, 'addPrinters')
                ? new \Symfony\Component\HttpClient\Response\MockResponse('{"code":0,"data":{"success":[],"fail":["32Z7NQJ70YC7549"],"failMsg":["32Z7NQJ70YC7549:1011"]}}')
                : new \Symfony\Component\HttpClient\Response\MockResponse('{"code":0,"data":0}');
        });
        $provider = new XpyunPrinterProvider($cipher, new XpyunClient($http));

        self::assertSame(
            ['registeredSerialNumbers' => ['32Z7NQJ70YC7549']],
            $provider->registerDevice($account, $device),
        );
    }

    public function testAlreadyRegisteredPrinterOutsideTheAccountIsRejected(): void
    {
        $cipher = new PrinterCredentialCipher('test-only-key');
        $account = new PrinterAccount('Account', 'developer@example.test', $cipher->encrypt('private-key'));
        $device = new PrinterDevice($account, 'Label', '32Z7NQJ70YC7549', PrinterDevice::KIND_LABEL, null, null, 40, 30);
        $http = new MockHttpClient(static function (string $method, string $url): \Symfony\Component\HttpClient\Response\MockResponse {
            return str_contains($url, 'addPrinters')
                ? new \Symfony\Component\HttpClient\Response\MockResponse('{"code":0,"data":{"success":[],"fail":["32Z7NQJ70YC7549"],"failMsg":["32Z7NQJ70YC7549:1011"]}}')
                : new \Symfony\Component\HttpClient\Response\MockResponse('{"code":1002,"msg":"PRINTER_NOT_REGISTERED","data":null}');
        });
        $provider = new XpyunPrinterProvider($cipher, new XpyunClient($http));

        try {
            $provider->registerDevice($account, $device);
            self::fail('Inaccessible existing registration was accepted.');
        } catch (PrinterProviderException $exception) {
            self::assertSame('registration_unconfirmed', $exception->providerCode);
            self::assertStringContainsString('not accessible from this account', $exception->getMessage());
        }
    }
}
