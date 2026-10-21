<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Service\PrinterCredentialCipher;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\XpyunClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PrinterJobServiceReprintTest extends TestCase
{
    public function testReprintCreatesNewJobFromStoredSnapshotWithoutRenderingAgain(): void
    {
        $cipher = new PrinterCredentialCipher('test-only-printer-key');
        $account = new PrinterAccount('Test account', 'developer-id', $cipher->encrypt('developer-key'));
        $device = new PrinterDevice($account, 'Receipt printer', 'TEST-SERIAL', 'receipt', 'store-1');
        $snapshot = ['kind' => 'receipt', 'widthMm' => 58, 'nodes' => [['type' => 'text', 'value' => 'Original template']]];
        $data = ['order' => ['number' => 'ORIGINAL-42']];
        $content = '<C>Frozen receipt<BR></C>';
        $source = new PrinterJob($device, 2, $snapshot, $data, $content, 'source-idempotency-key');
        $source->submitted('provider-original');

        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            return new MockResponse('{"code":0,"data":"provider-reprint"}');
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (object $job) use (&$persisted): bool {
            $persisted = $job;
            return $job instanceof PrinterJob;
        }));
        $entityManager->expects(self::exactly(2))->method('flush');

        $service = new PrinterJobService(
            $entityManager,
            new PrintDocumentRenderer(),
            $cipher,
            new XpyunClient($http),
        );
        $reprint = $service->reprint($source);

        self::assertSame($persisted, $reprint);
        self::assertNotSame($source->getUuid(), $reprint->getUuid());
        self::assertNotSame($source->getIdempotencyKey(), $reprint->getIdempotencyKey());
        self::assertSame($content, $reprint->getRenderedContent());
        self::assertSame($snapshot, $reprint->getTemplateSnapshot());
        self::assertSame($data, $reprint->getDataSnapshot());
        self::assertSame(2, $reprint->getCopies());
        self::assertSame(PrinterJob::STATUS_SUBMITTED, $reprint->getStatus());
        self::assertSame('provider-reprint', $reprint->getProviderOrderId());
        self::assertCount(1, $requests);
        self::assertSame('https://open.xpyun.net/api/openapi/xprinter/print', $requests[0][1]);
        $payload = json_decode($requests[0][2]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($content, $payload['content']);
        self::assertSame(2, $payload['copies']);
        self::assertNotSame($payload['idempotent'], substr(hash('sha256', $source->getIdempotencyKey()), 0, 20));
    }

    public function testUnknownOrSubmittingJobsCannotBeReprinted(): void
    {
        $account = new PrinterAccount('Disabled account', 'developer-id', '');
        $device = new PrinterDevice($account, 'Disabled printer', 'TEST-SERIAL', 'receipt');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $http = new MockHttpClient(static function (): never { self::fail('Unresolved reprint must not call the provider.'); });
        $service = new PrinterJobService($entityManager, new PrintDocumentRenderer(), new PrinterCredentialCipher(''), new XpyunClient($http));

        foreach ([PrinterJob::STATUS_UNKNOWN, PrinterJob::STATUS_SUBMITTING] as $status) {
            $source = new PrinterJob($device, 1, ['kind' => 'receipt', 'nodes' => []], [], 'stored', 'source-'.$status);
            if ($status === PrinterJob::STATUS_UNKNOWN) { $source->unknown(); }
            try {
                $service->reprint($source);
                self::fail('Expected unresolved jobs to be rejected.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('unresolved outcome', $exception->getMessage());
            }
        }
    }

    public function testDisabledSourceDeviceCannotBeReprinted(): void
    {
        $cipher = new PrinterCredentialCipher('test-only-printer-key');
        $account = new PrinterAccount('Account', 'developer-id', $cipher->encrypt('developer-key'));
        $device = (new PrinterDevice($account, 'Printer', 'TEST-SERIAL', 'receipt'))->setEnabled(false);
        $source = new PrinterJob($device, 1, ['kind' => 'receipt', 'nodes' => []], [], 'stored', 'disabled-source');
        $source->submitted('provider-order');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $http = new MockHttpClient(static function (): never { self::fail('Disabled hardware must not call the provider.'); });
        $service = new PrinterJobService($entityManager, new PrintDocumentRenderer(), $cipher, new XpyunClient($http));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Enable the source printer device and account');
        $service->reprint($source);
    }
}
