<?php

namespace App\Core\Utils;

class StringCase
{
    /**
     */
    public static function dashesToCamelCase(string $string, bool $capitalizeFirstCharacter = false): string
    {
        $str = str_replace('-', '', ucwords($string, '-'));

        if (!$capitalizeFirstCharacter) {
            $str = lcfirst($str);
        }

        return $str;
    }
}
