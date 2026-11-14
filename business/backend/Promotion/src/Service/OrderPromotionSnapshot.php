<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Service;

/**
 * Compact, server-owned record of the promotions that produced an order quote.
 *
 * Trade exposes promotions through {@see \App\Trade\Service\Pricing\PriceCalculationResult::$meta}
 * only while quoting. Persisting that structure verbatim would copy full campaign
 * configuration into every order, so this snapshot keeps just the display fields
 * the admin needs. Orders created before this snapshot existed have no key.
 *
 * @phpstan-type PromotionSnapshot array{applied: list<array{id: int|null, name: string, type: string|null, phase: string, amount: int}>, originalAmount?: int, discountAmount?: int, recordedAt: string}
 * @phpstan-type OrderDiscountSummary array{originalAmount: int, discountAmount: int, discounts: list<array{name: string, type: string|null, amount: int}>}
 */
final class OrderPromotionSnapshot
{
    public const METADATA_KEY = '_promotion';
    public const PHASE_INNER = 'inner';
    public const PHASE_OUTER = 'outer';
    public const PHASE_BEST_PRICE = 'best_price';

    /**
     * @param array<string, mixed>|null $meta
     * @return PromotionSnapshot|null
     */
    public static function fromPricingMeta(?array $meta): ?array
    {
        if ($meta === null || !is_array($meta['promotion'] ?? null)) {
            return null;
        }

        $promotion = $meta['promotion'];
        $originalAmount = self::integer($promotion['originalAmount'] ?? null);
        $discountAmount = self::integer($promotion['discountAmount'] ?? null);
        $applied = [];
        foreach (is_array($promotion['inner'] ?? null) ? $promotion['inner'] : [] as $entry) {
            self::append($applied, self::normalizeEntry($entry, self::PHASE_INNER));
        }
        self::append($applied, self::normalizeEntry($promotion['outer'] ?? null, self::PHASE_OUTER));
        self::append($applied, self::normalizeEntry($promotion['bestPrice'] ?? null, self::PHASE_BEST_PRICE));

        $snapshot = [
            'applied' => $applied,
            'recordedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
        if ($originalAmount !== null) {
            $snapshot['originalAmount'] = $originalAmount;
        }
        if ($discountAmount !== null) {
            $snapshot['discountAmount'] = $discountAmount;
        }

        return $snapshot;
    }

    /**
     * Add a snapshot without touching metadata when no quote was recorded.
     *
     * @param array<string, mixed>|null $metadata
     * @param PromotionSnapshot|null    $snapshot
     * @return array<string, mixed>
     */
    public static function attach(?array $metadata, ?array $snapshot): array
    {
        $metadata ??= [];
        if ($snapshot !== null) {
            $metadata[self::METADATA_KEY] = $snapshot;
        }

        return $metadata;
    }

    /**
     * Replace an inherited snapshot with the current quote.
     *
     * Staff replacements copy the original order metadata; the previous campaign
     * record must not survive when the replacement was quoted again.
     *
     * @param array<string, mixed>|null $metadata
     * @param PromotionSnapshot|null    $snapshot
     * @return array<string, mixed>
     */
    public static function replace(?array $metadata, ?array $snapshot): array
    {
        $metadata ??= [];
        unset($metadata[self::METADATA_KEY]);

        return self::attach($metadata, $snapshot);
    }

    /**
     * @param list<array{id: int|null, name: string, type: string|null, phase: string, amount: int}> $applied
     * @param array{id: int|null, name: string, type: string|null, phase: string, amount: int}|null $entry
     */
    private static function append(array &$applied, ?array $entry): void
    {
        if ($entry !== null) {
            $applied[] = $entry;
        }
    }

    /**
     * @return array{id: int|null, name: string, type: string|null, phase: string, amount: int}|null
     */
    private static function normalizeEntry(mixed $entry, string $phase): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $id = is_numeric($entry['promotionId'] ?? null) ? (int) $entry['promotionId'] : null;
        $name = self::text($entry['promotionName'] ?? null) ?? self::text($entry['templateName'] ?? null);
        if ($name === null && $id === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name ?? '#'.$id,
            'type' => self::text($entry['type'] ?? null),
            'phase' => $phase,
            'amount' => self::integer($entry['discountAmount'] ?? null) ?? 0,
        ];
    }

    /**
     * Build the only discount fields that may be exposed to staff and customers.
     *
     * New orders contain exact per-campaign amounts. Historical orders may only
     * contain campaign names, so their known total reduction is emitted once
     * rather than inventing a split.
     *
     * @param array<string, mixed>|null $metadata
     * @return OrderDiscountSummary
     */
    public static function summary(?array $metadata, int $totalAmount, int $itemOriginalAmount = 0): array
    {
        $totalAmount = max(0, $totalAmount);
        $snapshot = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [];
        $recordedOriginal = self::integer($snapshot['originalAmount'] ?? null);
        $originalAmount = max($totalAmount, $itemOriginalAmount, $recordedOriginal ?? 0);
        $discountAmount = max(0, $originalAmount - $totalAmount);
        $discounts = [];
        $remaining = $discountAmount;
        $names = [];

        foreach (is_array($snapshot['applied'] ?? null) ? $snapshot['applied'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = self::text($entry['name'] ?? null);
            if ($name !== null) {
                $names[] = $name;
            }
            $amount = min($remaining, self::integer($entry['amount'] ?? null) ?? 0);
            if ($name === null || $amount <= 0) {
                continue;
            }
            $discounts[] = [
                'name' => $name,
                'type' => self::text($entry['type'] ?? null),
                'amount' => $amount,
            ];
            $remaining -= $amount;
        }

        if ($remaining > 0) {
            $discounts[] = [
                'name' => $names === [] ? '订单优惠' : implode(' + ', array_values(array_unique($names))),
                'type' => 'order_discount',
                'amount' => $remaining,
            ];
        }

        return [
            'originalAmount' => $originalAmount,
            'discountAmount' => $discountAmount,
            'discounts' => $discounts,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
