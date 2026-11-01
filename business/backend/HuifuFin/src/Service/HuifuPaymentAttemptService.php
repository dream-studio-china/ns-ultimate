<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<HuifuPaymentAttempt> */
final class HuifuPaymentAttemptService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, HuifuPaymentAttempt::class);
    }
}
