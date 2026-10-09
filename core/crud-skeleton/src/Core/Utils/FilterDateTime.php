<?php

namespace App\Core\Utils;

class FilterDateTime
{
    public function get(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime
    {
        return new \DateTime($time, $timezone);
    }
}
