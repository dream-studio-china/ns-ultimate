<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Trade\Entity\Order;

/** Pure, bounded matching only; this class does not schedule or send print jobs. */
final class OrderPrintRuleMatcher
{
    private const EVENTS = ['confirmed', 'paid', 'accepted'];
    private const OPERATORS = ['equals', 'not_equals', 'exists', 'not_exists'];

    /** @param array<string, mixed> $rule */
    public function matches(array $rule, string $event, Order $order): bool
    {
        if (($rule['enabled'] ?? null) !== true || !in_array($event, self::EVENTS, true) || !$this->isValid($rule)) {
            return false;
        }
        $events = $rule['events'];
        $conditions = $rule['conditions'];
        $mode = $rule['match'];
        if (!in_array($event, $events, true)) {
            return false;
        }

        $results = [];
        foreach ($conditions as $condition) {
            [$exists, $value] = $this->readField($order, $condition['field']);
            if (in_array($condition['operator'], ['equals', 'not_equals'], true)
                && $exists && !is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                return false;
            }
            $results[] = match ($condition['operator']) {
                'exists' => $exists,
                'not_exists' => !$exists,
                'equals' => $exists && $value === $condition['value'],
                'not_equals' => $exists && $value !== $condition['value'],
            };
        }

        return $results === [] || ($mode === 'all' ? !in_array(false, $results, true) : in_array(true, $results, true));
    }

    /** Validate the condition subset without needing an order or event.
     * @param array<string, mixed> $rule */
    public function isValid(array $rule): bool
    {
        $events = $rule['events'] ?? null;
        $conditions = $rule['conditions'] ?? null;
        $mode = $rule['match'] ?? null;
        if (!is_array($events) || !array_is_list($events) || $events === [] || count($events) > count(self::EVENTS)
            || !is_array($conditions) || !array_is_list($conditions) || count($conditions) > 20
            || !in_array($mode, ['all', 'any'], true)
            || ($mode === 'any' && $conditions === [])) {
            return false;
        }
        $seen = [];
        foreach ($events as $configuredEvent) {
            if (!is_string($configuredEvent) || !in_array($configuredEvent, self::EVENTS, true) || isset($seen[$configuredEvent])) {
                return false;
            }
            $seen[$configuredEvent] = true;
        }

        foreach ($conditions as $condition) {
            if (!is_array($condition) || !$this->validCondition($condition)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $condition */
    private function validCondition(array $condition): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;
        if (!is_string($field) || strlen($field) > 128
            || ($field !== 'status' && preg_match('/^metadata(?:\.[A-Za-z_][A-Za-z0-9_-]{0,63}){1,4}$/D', $field) !== 1)
            || !is_string($operator) || !in_array($operator, self::OPERATORS, true)) {
            return false;
        }
        if (in_array($operator, ['equals', 'not_equals'], true)) {
            return array_key_exists('value', $condition) && (is_string($condition['value']) || is_int($condition['value']) || is_bool($condition['value']) || $condition['value'] === null)
                && (!is_string($condition['value']) || strlen($condition['value']) <= 256);
        }
        return !array_key_exists('value', $condition);
    }

    /** @return array{bool, mixed} */
    private function readField(Order $order, string $field): array
    {
        if ($field === 'status') {
            return [true, $order->getStatus()];
        }
        $value = $order->getMetadata();
        foreach (array_slice(explode('.', $field), 1) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return [false, null];
            }
            $value = $value[$part];
        }
        return [true, $value];
    }
}
