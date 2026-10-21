<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<PrinterJob> */
final class PrinterJobRepositoryService extends BaseService
{
    public function __construct(ContainerInterface $container) { parent::__construct($container, PrinterJob::class); }
}
