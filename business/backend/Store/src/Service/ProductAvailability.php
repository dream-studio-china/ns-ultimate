<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use NsUltimate\Business\Store\Entity\Product;

final class ProductAvailability
{
    /** Daily half-open interval in the owning store's timezone; overnight windows are supported. */
    public function isAvailable(Product $product, \DateTimeImmutable $now): bool
    {
        if ($product->isSoldout()) return false;
        $settings = $product->getSettings() ?? [];
        $start = $settings['startSaleTime'] ?? '';
        $end = $settings['endSaleTime'] ?? '';
        if ($start === '' && $end === '') return true;
        $valid = static fn(mixed $value): bool => is_string($value) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) === 1;
        if (!$valid($start) || !$valid($end) || $start === $end) return false;
        try { $timezone = new \DateTimeZone($product->getStore()?->getTimezone() ?? 'UTC'); }
        catch (\Exception) { return false; }
        $time = $now->setTimezone($timezone)->format('H:i');
        return $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }
}
