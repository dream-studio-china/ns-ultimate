<?php

declare(strict_types=1);

namespace NsUltimate\Business\Note\Service;

use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use NsUltimate\Business\Note\Entity\Note;

/** @extends BaseService<Note> */
final class NoteService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Note::class);
    }
}
