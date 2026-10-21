<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<PrinterTemplate> */
final class PrinterTemplateService extends BaseService
{
    public function __construct(ContainerInterface $container) { parent::__construct($container, PrinterTemplate::class); }
}
