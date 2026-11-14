<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Value\PrintItemContext;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Validates and evaluates a template item filter.
 *
 * The filter is a normal Symfony expression evaluated with a read-only
 * `entity` context exposing item getters and its product/category. It is
 * validated when the template is saved and evaluated per order item when a
 * print request is built; invalid filters fail closed.
 */
final class OrderItemFilter
{
    public const MAX_LENGTH = 500;

    private ?ExpressionLanguage $language = null;

    public function isFiltering(?string $filter): bool
    {
        // DEPRECATED: the legacy "all" token still means no filtering, including empty orders.
        return !in_array($filter === null ? null : trim($filter), [null, '', PrinterTemplate::ITEMS_ALL], true);
    }

    public function validate(?string $filter): void
    {
        if ($filter === null || trim($filter) === '') {
            return;
        }
        if (strlen($filter) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Item filter must be at most 500 characters.');
        }
        // DEPRECATED: accept legacy tokens without rewriting persisted configurations.
        if (in_array(trim($filter), [PrinterTemplate::ITEMS_ALL, PrinterTemplate::ITEMS_KITCHEN], true)) {
            return;
        }
        try {
            $this->language()->parse($filter, ['entity']);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Item filter syntax is invalid.');
        }
    }

    public function matches(?string $filter, PrintItemContext $item): bool
    {
        if ($filter === null || !$this->isFiltering($filter)) {
            return true;
        }
        // DEPRECATED: preserve strict boolean opt-in, not expression-language coercion.
        if (trim($filter) === PrinterTemplate::ITEMS_KITCHEN) {
            return ($item->getProductSettings()['kitchenPrinterEnable'] ?? false) === true;
        }
        // A missing key or property fails closed instead of emitting a PHP warning.
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            return (bool) $this->language()->evaluate($filter, ['entity' => $item]);
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    private function language(): ExpressionLanguage
    {
        return $this->language ??= new ExpressionLanguage();
    }
}
