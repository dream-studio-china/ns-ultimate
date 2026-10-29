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
}
