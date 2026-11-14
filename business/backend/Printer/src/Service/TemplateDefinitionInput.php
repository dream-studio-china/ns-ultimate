<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

/** Preserve literal null properties when the host form cleans nested objects. */
final class TemplateDefinitionInput
{
    /** @return array<string, mixed> */
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > 100_000 || !str_starts_with(ltrim($value), '{')) {
                throw new \InvalidArgumentException('Template definition must be a bounded JSON object.');
            }
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new \InvalidArgumentException('Template definition contains invalid JSON.', 0, $error);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('Template definition must be an object.');
        }
        return $value;
    }
}
