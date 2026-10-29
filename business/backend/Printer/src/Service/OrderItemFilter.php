<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

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

    public function validate(?string $filter): void
    {
        if ($filter === null || trim($filter) === '') {
            return;
        }
        if (strlen($filter) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Item filter must be at most 500 characters.');
        }
        try {
            $this->language()->parse($filter, ['entity']);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Item filter syntax is invalid.');
        }
    }

    public function matches(?string $filter, PrintItemContext $item): bool
    {
        if ($filter === null || trim($filter) === '') {
            return true;
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
