<?php

namespace App\Common\Service;

use App\Common\Entity\Page;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Page> */
final class PageService extends BaseService implements PageServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Page::class);
    }
}
