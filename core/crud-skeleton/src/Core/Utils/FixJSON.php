<?php

namespace App\Core\Utils;

class FixJSON
{
    /**
     * @return array<int, string>|string|null
     */
    public static function fixJSON(string $json): array|string|null
    {
        $regex = <<<'REGEX'
~
    "[^"\\]*(?:\\.|[^"\\]*)*"
    (*SKIP)(*F)
  | '([^'\\]*(?:\\.|[^'\\]*)*)'
~x
REGEX;

        return preg_replace_callback($regex, function($matches) {
            return '"' . preg_replace('~\\\\.(*SKIP)(*F)|"~', '\\"', $matches[1]) . '"';
        }, $json);
    }

    /**
     */
    public static function getJSONType(string $json): string|false
    {
        $obj = json_decode($json);

        // Not valid JSON
        if ($obj === null) return false;
        $json = ltrim($json);

        // Object
        if (strpos($json, '{') === 0) return 'object';

        // Array
        if (strpos($json, '[') === 0) return 'array';

        return false;
    }
}
