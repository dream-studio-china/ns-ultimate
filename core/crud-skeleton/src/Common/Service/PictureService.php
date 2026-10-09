<?php

namespace App\Common\Service;

use App\Common\Entity\Picture;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Picture> */
final class PictureService extends BaseService implements PictureServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Picture::class);
    }
}
