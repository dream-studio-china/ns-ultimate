<?php

namespace App\Common\Service;

use App\Common\Entity\Comment;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Comment> */
final class CommentService extends BaseService implements CommentServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Comment::class);
    }
}
