<?php

namespace App\Core\Utils;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class ArrayCommon
{
    /**
     * @param array<array-key, mixed> $array
     */
    public static function in_array(mixed $needle, array $array): bool
    {
        return in_array($needle, $array);
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function count(array $array): int
    {
        return count($array);
    }

    /**
     * @param array<array-key, mixed> ...$arrays
     * @return array<array-key, mixed>
     */
    public static function merge(array ...$arrays): array
    {
        return array_merge($arrays);
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<array-key, mixed>
     */
    public static function push(array $array, mixed $arrayPush): array
    {
        $array[] = $arrayPush;
        return $array;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function key_exist(int|string $key, array $array): bool
    {
        return array_key_exists($key, $array);
    }

    /**
     * @param array<array-key, mixed> $array
     * @param array<string, mixed> $external
     * @return array<array-key, mixed>
     */
    public static function filter(array $array, mixed $expression, array $external = []): array
    {
        return array_filter($array, function ($value) use ($expression, $external) {
            $expressionLanguage = new ExpressionLanguage();
            return $expressionLanguage->evaluate(
                $expression, array_merge(['value' => $value], $external)
            );
        });
    }

    /**
     * @param array<array-key, mixed> $array
     * @param array<string, mixed> $external
     * @return array<array-key, mixed>
     */
    public static function map(array $array, mixed $expression, array $external = []): array
    {
        return array_map(function($item) use ($expression, $external) {
            $expressionLanguage = new ExpressionLanguage();
            return $expressionLanguage->evaluate(
                $expression, array_merge(['item' => $item], $external)
            );
        }, $array);
    }

    /**
     * @param array<array-key, mixed> $array
     * @param array<string, mixed> $external
     */
    public static function reduce(array $array, mixed $expression, mixed $initial = null, array $external = []): mixed
    {
        return array_reduce($array, function($carry, $item) use ($expression, $external) {
            $expressionLanguage = new ExpressionLanguage();
            return $expressionLanguage->evaluate(
                $expression, array_merge(['carry' => $carry, 'item' => $item], $external)
            );
        }, $initial);
    }
}
