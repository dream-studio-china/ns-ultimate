<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
final class FullReductionStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'full_reduction';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $value = $this->resolveValue($action->data['value'] ?? 0, $config);
        $amount = (int) ($value * 100); // convert to cents
        $context->totalAmount = max(0, $context->totalAmount - $amount);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveValue(mixed $value, array $config): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && str_starts_with($value, 'config.')) {
            $key = substr($value, 7);
            return (float) ($config[$key] ?? 0);
        }
        return 0.0;
    }
}
