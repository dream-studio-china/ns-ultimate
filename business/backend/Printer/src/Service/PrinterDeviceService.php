<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<PrinterDevice> */
final class PrinterDeviceService extends BaseService
{
    public function __construct(ContainerInterface $container) { parent::__construct($container, PrinterDevice::class); }
}
