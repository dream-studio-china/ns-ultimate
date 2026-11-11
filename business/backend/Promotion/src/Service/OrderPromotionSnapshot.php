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
 * @phpstan-type PromotionSnapshot array{applied: list<array{id: int|null, name: string, type: string|null, phase: string}>, recordedAt: string}
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
        $applied = [];
        foreach (is_array($promotion['inner'] ?? null) ? $promotion['inner'] : [] as $entry) {
            self::append($applied, self::normalizeEntry($entry, self::PHASE_INNER));
        }
        self::append($applied, self::normalizeEntry($promotion['outer'] ?? null, self::PHASE_OUTER));
        self::append($applied, self::normalizeEntry($promotion['bestPrice'] ?? null, self::PHASE_BEST_PRICE));

        return [
            'applied' => $applied,
            'recordedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
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
     * @param list<array{id: int|null, name: string, type: string|null, phase: string}> $applied
     * @param array{id: int|null, name: string, type: string|null, phase: string}|null $entry
     */
    private static function append(array &$applied, ?array $entry): void
    {
        if ($entry !== null) {
            $applied[] = $entry;
        }
    }

    /**
     * @return array{id: int|null, name: string, type: string|null, phase: string}|null
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
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
