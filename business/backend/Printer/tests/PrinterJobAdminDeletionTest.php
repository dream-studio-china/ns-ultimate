<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Controller\Admin\PrinterJobController;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use App\Core\Controller\RestController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PrinterJobAdminDeletionTest extends TestCase
{
    public function testOnlyDefinitivelyFailedJobsWithoutProviderIdsCanBeDeleted(): void
    {
        $controller = (new \ReflectionClass(PrinterJobController::class))->newInstanceWithoutConstructor();
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('{}');
        foreach (['translator' => $translator, 'serializer' => $serializer] as $propertyName => $value) {
            $property = new \ReflectionProperty(RestController::class, $propertyName);
            $property->setValue($controller, $value);
        }
        $processDeletion = new \ReflectionMethod(PrinterJobController::class, 'processDeletion');
        $device = new PrinterDevice(new PrinterAccount(), 'Offline demo', 'offline-demo', 'receipt');

        $safe = new PrinterJob($device, 1, ['kind' => 'receipt', 'nodes' => []], [], 'local', 'offline-failed');
        $safe->failed('local_demo_not_submitted');
        self::assertNull($processDeletion->invoke($controller, $safe));

        foreach (['submitting', 'submitted', 'printed', 'unknown'] as $status) {
            $job = new PrinterJob($device, 1, ['kind' => 'receipt', 'nodes' => []], [], 'content', 'protected-'.$status);
            if ($status === PrinterJob::STATUS_SUBMITTED || $status === PrinterJob::STATUS_PRINTED) { $job->submitted('provider-'.$status); }
            if ($status === PrinterJob::STATUS_PRINTED) { $job->markPrinted(); }
            if ($status === PrinterJob::STATUS_UNKNOWN) { $job->unknown(); }
            self::assertInstanceOf(Response::class, $processDeletion->invoke($controller, $job), $status);
        }

        $failedAfterProviderId = new PrinterJob($device, 1, ['kind' => 'receipt', 'nodes' => []], [], 'content', 'failed-provider-id');
        $failedAfterProviderId->submitted('provider-id')->failed('provider-error');
        self::assertInstanceOf(Response::class, $processDeletion->invoke($controller, $failedAfterProviderId));
    }
}
