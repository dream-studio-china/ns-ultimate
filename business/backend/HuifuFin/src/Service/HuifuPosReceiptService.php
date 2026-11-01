<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\HuifuPosReceipt;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<HuifuPosReceipt> */
final class HuifuPosReceiptService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, HuifuPosReceipt::class);
    }
}
