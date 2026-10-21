<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;

/** Provider-specific transport boundary for printer account and device operations. */
interface PrinterProviderInterface
{
    public function code(): string;

    /** @return array{registeredSerialNumbers: list<string>} */
    public function registerDevice(PrinterAccount $account, PrinterDevice $device): array;

    /** @return array{status: int} */
    public function queryDeviceStatus(PrinterDevice $device): array;

    /** @return array{providerOrderId: string|null} */
    public function submitJob(PrinterJob $job): array;

    /** @return array{printed: bool} */
    public function queryJobStatus(PrinterJob $job): array;
}
