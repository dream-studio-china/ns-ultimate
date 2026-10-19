<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Service;

use App\Common\Entity\Content as CoreContent;
use App\Common\Service\ContentServiceInterface;
use App\Core\Service\BaseService;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Common\Entity\Content;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<CoreContent> */
final class ContentService extends BaseService implements ContentServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, CoreContent::class);
        $this->qbFactory = new ContentQueryBuilderFactory($this->em);
    }

    public function new(): Content
    {
        return new Content('');
    }

    public function list(mixed $object = null, mixed $order = null, bool $disableRequest = true): mixed
    {
        if ($object instanceof QueryBuilder) {
            ContentQueryBuilderFactory::joinExtension($object);
        }

        if (is_array($order)) {
            $mappedOrder = [];
            foreach ($order as $field => $direction) {
                $mappedOrder[$field === 'entity.sortOrder' ? 'commonContentExtension.sortOrder' : $field] = $direction;
            }
            $order = $mappedOrder;
        }

        $request = $disableRequest ? null : $this->getCurrentRequest();
        $originalOrder = $request?->query->get('@order');
        if (is_string($originalOrder)) {
            $request->query->set('@order', preg_replace(
                '/(?<![\w.])entity\.sortOrder\b/',
                'commonContentExtension.sortOrder',
                $originalOrder,
            ));
        }

        try {
            return parent::list($object, $order, $disableRequest);
        } finally {
            if (is_string($originalOrder)) {
                $request->query->set('@order', $originalOrder);
            }
        }
    }
}
