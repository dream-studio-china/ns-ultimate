<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Service;

use App\Common\Entity\Content as CoreContent;
use App\Common\Service\ContentServiceInterface;
use NsUltimate\Business\Common\Entity\Content;
use NsUltimate\Integration\Backend\Doctrine\EntityExtensionQueryBuilderFactory;
use NsUltimate\Integration\Backend\ExtendedEntityService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends ExtendedEntityService<CoreContent> */
final class ContentService extends ExtendedEntityService implements ContentServiceInterface
{
    public function __construct(ContainerInterface $container, EntityExtensionQueryBuilderFactory $extensionQueries)
    {
        parent::__construct($container, CoreContent::class, $extensionQueries);
    }

    public function new(): Content
    {
        return new Content('');
    }
}
