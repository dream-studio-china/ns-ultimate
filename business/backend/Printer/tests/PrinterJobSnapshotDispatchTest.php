<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Service\PrinterCredentialCipher;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use NsUltimate\Business\Printer\Service\XpyunClient;
use NsUltimate\Business\Printer\Service\XpyunPrinterProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PrinterJobSnapshotDispatchTest extends TestCase
{
    public function testSnapshotIsRenderedAndPersistedBeforeProviderCall(): void
    {
        $cipher = new PrinterCredentialCipher('test-only-key');
        $device = new PrinterDevice(new PrinterAccount('Account', 'developer', $cipher->encrypt('secret')), 'Printer', 'serial', 'receipt');
        $snapshot = ['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Original']]];
        $persisted = null;
        $flushed = false;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(static function ($job) use (&$persisted): void { $persisted = $job; });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$flushed): void { $flushed = true; });
        $http = new MockHttpClient(static function () use (&$persisted, &$flushed): MockResponse {
            self::assertTrue($flushed);
            self::assertInstanceOf(PrinterJob::class, $persisted);
            self::assertSame(PrinterJob::STATUS_SUBMITTING, $persisted->getStatus());
            return new MockResponse('{"code":0,"data":"provider-id"}');
        });
        $service = new PrinterJobService($entityManager, new PrintDocumentRenderer(), new PrinterProviderRegistry([new XpyunPrinterProvider($cipher, new XpyunClient($http))]));
        $job = $service->submitSnapshot($device, $snapshot, [], 1, 'snapshot-key');
        self::assertSame($snapshot, $job->getTemplateSnapshot());
        self::assertStringContainsString('Original', $job->getRenderedContent());
        self::assertSame(PrinterJob::STATUS_SUBMITTED, $job->getStatus());
    }

    public function testInvalidSnapshotNeverCallsProvider(): void
    {
        $device = new PrinterDevice(new PrinterAccount('Account', 'developer', ''), 'Printer', 'serial', 'receipt');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $service = new PrinterJobService($entityManager, new PrintDocumentRenderer(), new PrinterProviderRegistry([]));
        $this->expectException(\InvalidArgumentException::class);
        $service->submitSnapshot($device, ['kind' => 'label', 'nodes' => []], [], 1, 'snapshot-key');
    }
}
