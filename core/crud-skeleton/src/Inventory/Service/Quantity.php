<?php

declare(strict_types=1);

namespace App\Inventory\Service;

final class Quantity
{
    public const ZERO = '0.000000';

    public static function normalize(string $quantity, bool $positive = false): string
    {
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{1,6}))?$/', trim($quantity), $matches)) {
            throw new \InvalidArgumentException('Quantity must be a decimal string with at most six decimal places.');
        }

        $fraction = str_pad($matches[3] ?? '', 6, '0');
        $normalized = ltrim($matches[2], '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if (strlen($normalized) > 14) {
            throw new \InvalidArgumentException('Quantity exceeds decimal(20, 6).');
        }
        $result = ($matches[1] === '-' && ($normalized !== '0' || $fraction !== '000000') ? '-' : '') . $normalized . '.' . $fraction;

        if ($positive && self::compare($result, self::ZERO) <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        return $result;
    }

    public static function add(string $left, string $right): string
    {
        [$leftNegative, $leftDigits] = self::parts($left);
        [$rightNegative, $rightDigits] = self::parts($right);
        if ($leftNegative === $rightNegative) {
            return self::format($leftNegative, self::addDigits($leftDigits, $rightDigits));
        }

        $comparison = self::compareDigits($leftDigits, $rightDigits);
        if ($comparison === 0) {
            return self::ZERO;
        }
        if ($comparison > 0) {
            return self::format($leftNegative, self::subtractDigits($leftDigits, $rightDigits));
        }

        return self::format($rightNegative, self::subtractDigits($rightDigits, $leftDigits));
    }

    public static function subtract(string $left, string $right): string
    {
        return self::add($left, str_starts_with($right, '-') ? substr($right, 1) : '-' . $right);
    }

    public static function multiply(string $left, string $right): string
    {
        [, $leftDigits] = self::parts($left);
        [, $rightDigits] = self::parts($right);
        $negative = str_starts_with(self::normalize($left), '-') !== str_starts_with(self::normalize($right), '-');
        $result = '0';
        $reversed = strrev($rightDigits);
        foreach (str_split($reversed) as $position => $digit) {
            $carry = 0;
            $partial = '';
            foreach (str_split(strrev($leftDigits)) as $leftDigit) {
                $value = ((int) $leftDigit * (int) $digit) + $carry;
                $partial = (string) ($value % 10) . $partial;
                $carry = intdiv($value, 10);
            }
            $result = self::addDigits($result, (string) $carry . $partial . str_repeat('0', $position));
        }

        if (strlen($result) <= 12) {
            $result = str_pad($result, 13, '0', STR_PAD_LEFT);
        }
        $integer = substr($result, 0, -12);
        $fraction = substr($result, -12, 6);
        $remainder = substr($result, -6);
        if ($remainder !== '000000') {
            throw new \InvalidArgumentException('Quantity multiplication exceeds six decimal places.');
        }

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;

        return self::normalize(($negative ? '-' : '') . $integer . '.' . $fraction);
    }

    public static function compare(string $left, string $right): int
    {
        [$leftNegative, $leftDigits] = self::parts($left);
        [$rightNegative, $rightDigits] = self::parts($right);
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $comparison = self::compareDigits($leftDigits, $rightDigits);

        return $leftNegative ? -$comparison : $comparison;
    }

    /** @return array{bool, string} */
    private static function parts(string $quantity): array
    {
        $normalized = self::normalize($quantity);
        $negative = str_starts_with($normalized, '-');

        return [$negative, str_replace(['-', '.'], '', $normalized)];
    }

    private static function format(bool $negative, string $digits): string
    {
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        $digits = str_pad($digits, 7, '0', STR_PAD_LEFT);
        $result = substr($digits, 0, -6) . '.' . substr($digits, -6);

        return self::normalize($negative && $result !== self::ZERO ? '-' . $result : $result);
    }

    private static function compareDigits(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: $left <=> $right;
    }

    private static function addDigits(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $length = max(strlen($left), strlen($right));
        for ($index = 0; $index < $length; ++$index) {
            $value = (int) ($left[strlen($left) - 1 - $index] ?? 0) + (int) ($right[strlen($right) - 1 - $index] ?? 0) + $carry;
            $result = (string) ($value % 10) . $result;
            $carry = intdiv($value, 10);
        }

        return ($carry === 0 ? '' : (string) $carry) . $result;
    }

    private static function subtractDigits(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        for ($index = 0; $index < strlen($left); ++$index) {
            $value = (int) $left[strlen($left) - 1 - $index] - (int) ($right[strlen($right) - 1 - $index] ?? 0) - $borrow;
            if ($value < 0) {
                $value += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) $value . $result;
        }

        return ltrim($result, '0') ?: '0';
    }
}
