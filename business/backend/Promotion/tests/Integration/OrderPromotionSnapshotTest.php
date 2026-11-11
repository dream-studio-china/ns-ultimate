<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use NsUltimate\Business\Promotion\Service\OrderPromotionSnapshot;
use PHPUnit\Framework\TestCase;

final class OrderPromotionSnapshotTest extends TestCase
{
    public function testKeepsOnlyAppliedCampaignsAndNeverCandidatesOrConfiguration(): void
    {
        $entry = ['promotionId' => 1, 'promotionName' => 'Inner campaign', 'type' => 'discount', 'config' => ['rate' => 90]];
        $snapshot = OrderPromotionSnapshot::fromPricingMeta(['promotion' => [
            'inner' => [$entry],
            'outer' => ['promotionId' => 2, 'promotionName' => 'Outer campaign', 'type' => 'full_reduction'],
            'bestPrice' => ['promotionId' => 3, 'promotionName' => 'Winner', 'type' => 'discount', 'candidates' => [['promotionId' => 4]]],
        ]]);
        self::assertSame(['inner', 'outer', 'best_price'], array_column($snapshot['applied'], 'phase'));
        self::assertSame([1, 2, 3], array_column($snapshot['applied'], 'id'));
        self::assertSame(['id', 'name', 'type', 'phase'], array_keys($snapshot['applied'][0]));
    }

    public function testMissingQuoteDoesNotBecomeRecordedEmptyAndReplacementRemovesOldSnapshot(): void
    {
        self::assertNull(OrderPromotionSnapshot::fromPricingMeta([]));
        self::assertSame([], OrderPromotionSnapshot::fromPricingMeta(['promotion' => ['inner' => []]])['applied']);
        self::assertSame(['custom' => 'kept'], OrderPromotionSnapshot::replace(['_promotion' => ['old' => true], 'custom' => 'kept'], null));
    }
}
