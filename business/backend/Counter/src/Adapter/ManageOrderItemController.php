<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Trade\Controller\Manage\OrderItemController;
use App\Trade\Entity\OrderItem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidatorException;

#[IsGranted('ROLE_ADMIN')]
final class ManageOrderItemController extends OrderItemController
{
    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof OrderItem && $entity->getOrder()?->getStatus() === 'merged') {
            return $this->warning('Items of merged orders cannot be deleted.', 400, '', 400);
        }

        return parent::processDeletion($entity);
    }

    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if ($entity instanceof OrderItem && $entity->getOrder()?->getStatus() === 'merged') {
            throw new ValidatorException('Items of merged orders cannot be updated.');
        }

        return parent::processUpdateContent($content, $entity);
    }
}
