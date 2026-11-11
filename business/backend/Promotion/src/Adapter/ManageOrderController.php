<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Adapter;

use App\Trade\Entity\Order;
use NsUltimate\Business\Promotion\Service\OrderPromotionSnapshot;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ManageOrderController extends \App\Trade\Controller\Manage\OrderController
{
    use OrderPromotionCreation;

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        $content = parent::processUpdateContent($content, $entity);
        if ($entity instanceof Order && array_key_exists('metadata', $content)) {
            $stored = $entity->getMetadata();
            $metadata = $content['metadata'];
            if ($metadata !== null && !is_array($metadata)) {
                throw new \InvalidArgumentException('Metadata must be an object or null.');
            }
            $metadata = OrderPromotionSnapshot::replace($metadata, $stored[OrderPromotionSnapshot::METADATA_KEY] ?? null);
            $content['metadata'] = $metadata === [] && $content['metadata'] === null ? null : $metadata;
        }

        return $content;
    }
}
