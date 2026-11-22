<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

final class LedgerInput
{
    /** @param array<array-key, mixed> $input */
    public static function fields(array $input, string ...$allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new LedgerError('unexpected_field');
        }
    }

    public static function text(mixed $value, int $limit, bool $optional = false): string
    {
        if (!is_string($value) || strlen($value = trim($value)) > $limit || (!$optional && $value === '') || str_contains($value, "\0")) {
            throw new LedgerError('invalid_text');
        }

        return $value;
    }

    public static function metadata(mixed $value): \stdClass
    {
        if (!$value instanceof \stdClass) {
            throw new LedgerError('invalid_journal_metadata');
        }
        try {
            if (strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 12)) > 8192) {
                throw new LedgerError('invalid_journal_metadata');
            }
        } catch (\JsonException) {
            throw new LedgerError('invalid_journal_metadata');
        }

        return self::sortMetadataObject($value);
    }

    private static function sortMetadataObject(\stdClass $value): \stdClass
    {
        $fields = (array) $value;
        ksort($fields, SORT_STRING);

        return (object) array_map(self::sortMetadataValue(...), $fields);
    }

    private static function sortMetadataValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \stdClass => self::sortMetadataObject($value),
            is_array($value) => array_map(self::sortMetadataValue(...), $value),
            default => $value,
        };
    }

    public static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new LedgerError('invalid_date');
        }

        return $value;
    }

    public static function code(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,31}\z/', $value)) {
            throw new LedgerError('invalid_account_code');
        }

        return $value;
    }

    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value)) {
            throw new LedgerError('invalid_identifier');
        }

        return strtolower($value);
    }

    public static function revision(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value >= 2147483647) {
            throw new LedgerError('invalid_revision');
        }

        return $value;
    }

    public static function page(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,4}\z/', $value)) {
            throw new LedgerError('invalid_page');
        }

        return (int) $value;
    }

    public static function amount(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,18}\z/', $value)
            || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new LedgerError('invalid_amount');
        }

        return $value;
    }
}
