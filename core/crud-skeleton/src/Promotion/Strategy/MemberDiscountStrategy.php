<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
final class MemberDiscountStrategy implements PromotionStrategyInterface
{
    /** @var array<string, int> */
    private const LEVEL_RANK = [
        Profile::LEVEL_BRONZE => 0,
        Profile::LEVEL_SILVER => 1,
        Profile::LEVEL_GOLD => 2,
        Profile::LEVEL_PLATINUM => 3,
        Profile::LEVEL_DIAMOND => 4,
    ];

    public static function supportedType(): string
    {
        return 'member_discount';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $minLevel = $config['min_level'] ?? 'bronze';
        $rate = (float) ($action->data['rate'] ?? 100);

        $user = $context->user;
        if (!$user instanceof User || !$user->getProfile()) {
            return;
        }

        $userLevel = $user->getProfile()->getLevel();
        $minRank = self::LEVEL_RANK[$minLevel] ?? 0;
        $userRank = self::LEVEL_RANK[$userLevel] ?? 0;

        if ($userRank < $minRank) {
            return;
        }

        $discount = (int) ($context->totalAmount * (100 - $rate) / 100);
        $context->totalAmount = max(0, $context->totalAmount - $discount);
    }
}
