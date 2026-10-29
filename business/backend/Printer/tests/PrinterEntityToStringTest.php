<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use PHPUnit\Framework\TestCase;

final class PrinterEntityToStringTest extends TestCase
{
    public function testPrinterEntitiesHaveReadableSafeStringRepresentations(): void
    {
        $account = new PrinterAccount('Kitchen account', 'developer', 'secret-ciphertext');
        $device = new PrinterDevice($account, 'Kitchen receipt printer', 'SERIAL-1', PrinterDevice::KIND_RECEIPT);
        $template = new PrinterTemplate('Kitchen receipt', PrinterDevice::KIND_RECEIPT);
        $job = new PrinterJob($device, 1, ['kind' => 'receipt'], [], 'private rendered content', 'job-key');

        self::assertSame('XPYUN · Kitchen account', (string) $account);
        self::assertSame('XPYUN · Kitchen receipt printer', (string) $device);
        self::assertSame('Kitchen receipt', (string) $template);
        self::assertStringContainsString('Receipt job submitting', (string) $job);
        self::assertStringNotContainsString('secret-ciphertext', (string) $account);
        self::assertStringNotContainsString('private rendered content', (string) $job);
    }

    public function testTemplateDefaultsAndValidatesTicketOptions(): void
    {
        $template = new PrinterTemplate('Kitchen receipt', PrinterDevice::KIND_RECEIPT);
        self::assertSame(PrinterTemplate::TICKET_SINGLE, $template->getTicketMode());
        self::assertNull($template->getItemFilter());
        self::assertSame(PrinterTemplate::TICKET_PER_UNIT, $template->setTicketMode('per_unit')->getTicketMode());
        self::assertSame('entity.getQuantity() > 1', $template->setItemFilter('  entity.getQuantity() > 1  ')->getItemFilter());
        self::assertNull($template->setItemFilter('')->getItemFilter());

        try {
            $template->setTicketMode('per_order');
            self::fail('Invalid ticket mode was accepted.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(PrinterTemplate::TICKET_PER_UNIT, $template->getTicketMode());
    }
}
