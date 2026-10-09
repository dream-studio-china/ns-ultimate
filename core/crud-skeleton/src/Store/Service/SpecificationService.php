<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\Specification;

/** @extends BaseService<\App\Store\Entity\Specification> */
final class SpecificationService extends BaseService implements SpecificationServiceInterface
{
    public function __construct(
        \Symfony\Component\DependencyInjection\ContainerInterface $container,
    ) {
        parent::__construct($container, Specification::class);
    }
}
