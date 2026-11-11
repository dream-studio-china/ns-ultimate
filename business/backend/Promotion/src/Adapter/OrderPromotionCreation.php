<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Adapter;

use App\Trade\Service\StoreContextResolverInterface;
use NsUltimate\Business\Promotion\Service\OrderPromotionSnapshot;
use Symfony\Contracts\Service\Attribute\Required;

/** Explicitly carry this creation's quote metadata through the existing CRUD hooks. */
trait OrderPromotionCreation
{
    private StoreContextResolverInterface $promotionStoreContextResolver;

    #[Required]
    public function setPromotionStoreContextResolver(StoreContextResolverInterface $resolver): void
    {
        $this->promotionStoreContextResolver = $resolver;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        $items = $content['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Items are required.');
        }
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Items must be a list.');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each item must be an object.');
            }
        }

        $storeContext = $this->promotionStoreContextResolver->resolve();
        $requestedCurrency = $content['currency'] ?? null;
        $currency = $storeContext !== null ? $storeContext->currency : ($requestedCurrency ?? 'CNY');
        if ($storeContext !== null && $requestedCurrency !== null
            && strtoupper((string) $requestedCurrency) !== strtoupper($currency)) {
            throw new \InvalidArgumentException(sprintf('Currency mismatch: store %s expects %s, got %s', $storeContext->storeCode, $currency, (string) $requestedCurrency));
        }

        $result = $this->service->calculatePrices($items, $currency, $storeContext?->storeCode, $content['meta'] ?? []);
        $content['__calculatedItems'] = $result->items;
        $content['__totalAmount'] = $result->totalAmount;
        $content['__currency'] = $currency;
        $content['__storeContext'] = $storeContext;
        $content['__notes'] = $content['notes'] ?? null;
        $content['__metadata'] = OrderPromotionSnapshot::replace(
            $content['metadata'] ?? null,
            OrderPromotionSnapshot::fromPricingMeta($result->meta),
        );

        return $content;
    }
}
