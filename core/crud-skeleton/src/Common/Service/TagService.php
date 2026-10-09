<?php

namespace App\Common\Service;

use App\Common\Entity\Tag;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Tag> */
final class TagService extends BaseService implements TagServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Tag::class);
    }
}
