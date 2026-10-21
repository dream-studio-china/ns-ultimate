<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<PrinterAccount> */
final class PrinterAccountService extends BaseService
{
    public function __construct(ContainerInterface $container) { parent::__construct($container, PrinterAccount::class); }
}
