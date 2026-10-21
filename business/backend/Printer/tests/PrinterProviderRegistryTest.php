<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Service\PrinterProviderInterface;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use PHPUnit\Framework\TestCase;

final class PrinterProviderRegistryTest extends TestCase
{
    public function testProviderIsSelectedByTheAccountProviderCode(): void
    {
        $provider = $this->createStub(PrinterProviderInterface::class);
        $provider->method('code')->willReturn(PrinterAccount::PROVIDER_XPYUN);
        $registry = new PrinterProviderRegistry([$provider]);

        self::assertTrue($registry->has(PrinterAccount::PROVIDER_XPYUN));
        self::assertFalse($registry->has(PrinterAccount::PROVIDER_DELICLOUD));
        self::assertSame($provider, $registry->get(PrinterAccount::PROVIDER_XPYUN));
        $this->expectException(\DomainException::class);
        $registry->get(PrinterAccount::PROVIDER_DELICLOUD);
    }
}
