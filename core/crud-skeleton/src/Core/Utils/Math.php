<?php

namespace App\Core\Utils;

class Math
{
    // user defined
    public static function random(int|float $min = 0, int|float $max = 1): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    public static function locationDistance(int|float $longitude1, int|float $latitude1, int|float $longitude2, int|float $latitude2): float
    {
        $radian = function (int|float $d): float {
            return $d * 3.1415926535898 / 180.0;
        };
        $radLat1 = $radian ($latitude1);
        $radLat2 = $radian ($latitude2);
        $a = $radian ($latitude1) - $radian ($latitude2);
        $b = $radian ($longitude1) - $radian ($longitude2);

        $s = 2 * asin(sqrt(pow(sin($a / 2), 2) + cos($radLat1) *
                cos($radLat2) * pow(sin($b / 2), 2)));
        $s = $s * 6378.137;
        $s = round($s * 10000) / 10000;

        return $s;
    }

    // constrain
    const M_E = 2.7182818284590452354;
    const M_EULER = 0.57721566490153286061;
    const M_LNPI = 1.14472988584940017414;
    const M_LN2 = 0.69314718055994530942;
    const M_LN10 = 2.30258509299404568402;
    const M_LOG2E = 1.4426950408889634074;
    const M_LOG10E = 0.43429448190325182765;
    const M_PI = 3.14159265358979323846;
    const M_PI_2 = 1.57079632679489661923;
    const M_PI_4 = 0.78539816339744830962;
    const M_1_PI = 0.31830988618379067154;
    const M_2_PI = 0.63661977236758134308;
    const M_SQRTPI = 1.77245385090551602729;
    const M_2_SQRTPI = 1.12837916709551257390;
    const M_SQRT1_2 = 0.70710678118654752440;
    const M_SQRT2 = 1.41421356237309504880;
    const M_SQRT3 = 1.73205080756887729352;

    // common
    public static function abs(int|float $x): int|float { return abs($x); }
    public static function acos(int|float $x): float { return acos($x); }
    public static function acosh(int|float $x): float { return acosh($x); }
    public static function asin(int|float $x): float { return asin($x); }
    public static function asinh(int|float $x): float { return asinh($x); }
    public static function atan(int|float $x): float { return atan($x); }
    public static function atan2(int|float $y, int|float $x): float { return atan2($y, $x); }
    public static function atanh(int|float $x): float { return atanh($x); }
    public static function base_convert(string $number, int $frombase, int $tobase): string { return base_convert($number, $frombase, $tobase); }
    public static function bindec(string $x): int|float { return bindec($x); }
    public static function ceil(int|float $x): float { return ceil($x); }
    public static function cos(int|float $x): float { return cos($x); }
    public static function cosh(int|float $x): float { return cosh($x); }
    public static function decbin(int $x): string { return decbin($x); }
    public static function dechex(int $x): string { return dechex($x); }
    public static function decoct(int $x): string { return decoct($x); }
    public static function deg2rad(int|float $x): float { return deg2rad($x); }
    public static function exp(int|float $x): float { return exp($x); }
    public static function expm1(int|float $x): float { return expm1($x); }
    public static function floor(int|float $x): float { return floor($x); }
    public static function fmod(int|float $x, int|float $y): float { return fmod($x, $y); }
    public static function getrandmax(): int { return getrandmax(); }
    public static function hexdec(string $x): int|float { return hexdec($x); }
    public static function hypot(int|float $x, int|float $y): float { return hypot($x, $y); }
    public static function is_finite(int|float $x): bool { return is_finite($x); }
    public static function is_infinite(int|float $x): bool { return is_infinite($x); }
    public static function is_nan(int|float $x): bool { return is_nan($x); }
    public static function lcg_value(): float { return lcg_value(); }
    public static function log(int|float $x): float { return log($x); }
    public static function log10(int|float $x): float { return log10($x); }
    public static function log1p(int|float $x): float { return log1p($x); }
    public static function max(mixed $value, mixed ...$values): mixed { return max($value, ...$values); }
    public static function min(mixed $value, mixed ...$values): mixed { return min($value, ...$values); }
    public static function mt_getrandmax(): int { return mt_getrandmax(); }
    public static function mt_rand(int $x): int { return mt_rand($x); }
    public static function mt_srand(?int $seed = null, int $mode = MT_RAND_MT19937): void { mt_srand($seed, $mode); }
    public static function octdec(string $x): int|float { return octdec($x); }
    public static function pi(): float { return pi(); }
    public static function pow(int|float $x, int|float $y): int|float { return pow($x, $y); }
    public static function rad2deg(int|float $x): float { return rad2deg($x); }
    public static function rand(int $x): int { return rand($x); }
    public static function round(int|float $num, int $precision = 0, int $mode = PHP_ROUND_HALF_UP): float
    {
        return match ($mode) {
            PHP_ROUND_HALF_UP, PHP_ROUND_HALF_DOWN, PHP_ROUND_HALF_EVEN, PHP_ROUND_HALF_ODD => round($num, $precision, $mode),
            default => throw new \InvalidArgumentException('Invalid rounding mode.'),
        };
    }
    public static function sin(int|float $x): float { return sin($x); }
    public static function sinh(int|float $x): float { return sinh($x); }
    public static function sqrt(int|float $x): float { return sqrt($x); }
    public static function srand(?int $seed = null, int $mode = MT_RAND_MT19937): void { srand($seed, $mode); }
    public static function tan(int|float $x): float { return tan($x); }
    public static function tanh(int|float $x): float { return tanh($x); }
}
