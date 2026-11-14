<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use NsUltimate\Business\Promotion\Service\OrderPromotionSnapshot;
use PHPUnit\Framework\TestCase;

final class OrderPromotionSnapshotTest extends TestCase
{
    public function testKeepsOnlyAppliedCampaignsAndNeverCandidatesOrConfiguration(): void
    {
        $entry = ['promotionId' => 1, 'promotionName' => 'Inner campaign', 'type' => 'discount', 'discountAmount' => 200, 'config' => ['rate' => 90]];
        $snapshot = OrderPromotionSnapshot::fromPricingMeta(['promotion' => [
            'originalAmount' => 2400,
            'discountAmount' => 500,
            'inner' => [$entry],
            'outer' => ['promotionId' => 2, 'promotionName' => 'Outer campaign', 'type' => 'full_reduction', 'discountAmount' => 200],
            'bestPrice' => ['promotionId' => 3, 'promotionName' => 'Winner', 'type' => 'discount', 'discountAmount' => 100, 'candidates' => [['promotionId' => 4]]],
        ]]);
        self::assertSame(['inner', 'outer', 'best_price'], array_column($snapshot['applied'], 'phase'));
        self::assertSame([1, 2, 3], array_column($snapshot['applied'], 'id'));
        self::assertSame([200, 200, 100], array_column($snapshot['applied'], 'amount'));
        self::assertSame(2400, $snapshot['originalAmount']);
        self::assertSame(500, $snapshot['discountAmount']);
        self::assertSame(['id', 'name', 'type', 'phase', 'amount'], array_keys($snapshot['applied'][0]));
    }

    public function testMissingQuoteDoesNotBecomeRecordedEmptyAndReplacementRemovesOldSnapshot(): void
    {
        self::assertNull(OrderPromotionSnapshot::fromPricingMeta([]));
        self::assertSame([], OrderPromotionSnapshot::fromPricingMeta(['promotion' => ['inner' => []]])['applied']);
        self::assertSame(['custom' => 'kept'], OrderPromotionSnapshot::replace(['_promotion' => ['old' => true], 'custom' => 'kept'], null));
    }

    public function testSummaryUsesExactCampaignAmountsAndKeepsHistoricalTotalAccurate(): void
    {
        $exact = OrderPromotionSnapshot::summary(['_promotion' => [
            'originalAmount' => 3680,
            'discountAmount' => 680,
            'applied' => [
                ['name' => '会员优惠', 'type' => 'member_discount', 'amount' => 500],
                ['name' => '满减活动', 'type' => 'full_reduction', 'amount' => 180],
            ],
        ]], 3000, 3680);
        self::assertSame(['originalAmount' => 3680, 'discountAmount' => 680, 'discounts' => [
            ['name' => '会员优惠', 'type' => 'member_discount', 'amount' => 500],
            ['name' => '满减活动', 'type' => 'full_reduction', 'amount' => 180],
        ]], $exact);

        $legacy = OrderPromotionSnapshot::summary(['_promotion' => [
            'applied' => [
                ['name' => '会员优惠'],
                ['name' => '满减活动'],
            ],
        ]], 3000, 3680);
        self::assertSame(3680, $legacy['originalAmount']);
        self::assertSame(680, $legacy['discountAmount']);
        self::assertSame([['name' => '会员优惠 + 满减活动', 'type' => 'order_discount', 'amount' => 680]], $legacy['discounts']);
    }
}
