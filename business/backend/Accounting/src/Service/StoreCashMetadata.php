<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class StoreCashMetadata
{
    private const UNITS = ['g' => ['g', 1], 'kg' => ['g', 1000], 'ml' => ['ml', 1], 'l' => ['ml', 1000], 'piece' => ['piece', 1]];

    /** @return array<string, mixed> */
    public function normalize(string $category, mixed $metadata): array
    {
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new LedgerError('invalid_metadata');
        }
        LedgerInput::fields($metadata, ...($category === 'purchase' ? ['version', 'item', 'quantity', 'unit', 'reference', 'extra'] : ['version', 'reference', 'extra']));
        if (($metadata['version'] ?? 1) !== 1) {
            throw new LedgerError('unsupported_metadata_version');
        }
        $result = ['version' => 1];
        if ($category === 'purchase') {
            $quantity = $metadata['quantity'] ?? null;
            $unit = $metadata['unit'] ?? null;
            if (!is_string($quantity) || !preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?\z/', $quantity)
                || BigDecimal::of($quantity)->isZero() || !is_string($unit) || !isset(self::UNITS[$unit])
                || ($unit === 'piece' && str_contains($quantity, '.'))) {
                throw new LedgerError('invalid_purchase_quantity');
            }
            $result += ['item' => LedgerInput::text($metadata['item'] ?? null, 160), 'quantity' => (string) BigDecimal::of($quantity)->strippedOfTrailingZeros(), 'unit' => $unit];
        }
        if (isset($metadata['reference'])) {
            $result['reference'] = LedgerInput::text($metadata['reference'], 120, true);
        }
        if (isset($metadata['extra'])) {
            $extra = $metadata['extra'];
            if (!is_array($extra) || count($extra) > 10) {
                throw new LedgerError('invalid_metadata');
            }
            foreach ($extra as $key => &$value) {
                if (!is_string($key) || !preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $key)) {
                    throw new LedgerError('invalid_metadata');
                }
                $value = LedgerInput::text($value, 160, true);
            }
            unset($value);
            ksort($extra);
            if ($extra !== []) {
                $result['extra'] = $extra;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $metadata
     * @return array{baseUnit: string, baseQuantity: string, unitPrice: string}
     */
    public function purchaseCost(int $amount, array $metadata): array
    {
        [$baseUnit, $factor] = self::UNITS[$metadata['unit']];
        $quantity = BigDecimal::of($metadata['quantity'])->multipliedBy($factor);

        return [
            'baseUnit' => $baseUnit, 'baseQuantity' => (string) $quantity->strippedOfTrailingZeros(),
            'unitPrice' => (string) BigDecimal::ofUnscaledValue($amount, 2)->dividedBy($quantity, 8, RoundingMode::HalfUp)->strippedOfTrailingZeros(),
        ];
    }
}
