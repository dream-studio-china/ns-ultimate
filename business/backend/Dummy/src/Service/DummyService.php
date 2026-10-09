<?php

declare(strict_types=1);

namespace NsUltimate\Business\Dummy\Service;

use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use NsUltimate\Business\Dummy\Entity\Dummy;

/** @extends BaseService<Dummy> */
final class DummyService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Dummy::class);
    }
}
