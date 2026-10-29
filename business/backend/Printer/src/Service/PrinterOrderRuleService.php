<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<PrinterOrderRule> */
final class PrinterOrderRuleService extends BaseService
{
    public function __construct(ContainerInterface $container) { parent::__construct($container, PrinterOrderRule::class); }
}
