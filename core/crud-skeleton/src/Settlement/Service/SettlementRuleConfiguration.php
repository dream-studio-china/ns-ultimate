<?php

declare(strict_types=1);

namespace App\Settlement\Service;

/** Validates and describes the closed JSON grammar accepted by SettlementRuleEngine. */
final class SettlementRuleConfiguration
{
    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'definition' => [
                'required' => ['appliesTo', 'recipient', 'formula'],
                'optional' => ['allocationKey', 'reasonCode', 'conflictMode', 'group', 'eligibility', 'scope'],
                'conflictModes' => ['stack', 'exclusive_group', 'stop'],
                'scopes' => ['order', 'order_item'],
            ],
            'recipient' => [
                'literal' => ['required' => ['resolver', 'type', 'id']],
                'context_candidate' => ['required' => ['resolver', 'key']],
                'fact_reference' => ['required' => ['resolver', 'typeFact', 'idFact']],
            ],
            'eligibility' => [
                'composite' => ['all', 'any', 'not'],
                'fact' => ['factEquals', 'factIn', 'intAtLeast', 'intAtMost', 'amountAtLeast', 'amountAtMost', 'occurredBefore', 'occurredAfter'],
            ],
            'formula' => ['fundingAmount', 'fixedAmount', 'factAmount', 'rateOf', 'multiplyByQuantity', 'add', 'subtract', 'minOf', 'maxOf'],
        ];
    }

    /** @param array<string, mixed> $definition */
    public function validate(array $definition): void
    {
        $appliesTo = $definition['appliesTo'] ?? null;
        if (!is_array($appliesTo) || !array_is_list($appliesTo) || $appliesTo === []) {
            throw new \InvalidArgumentException('appliesTo must be a non-empty list.');
        }
        foreach ($appliesTo as $subjectType) {
            $this->string($subjectType, 'appliesTo value');
        }

        $mode = $definition['conflictMode'] ?? 'stack';
        if (!in_array($mode, ['stack', 'exclusive_group', 'stop'], true)) {
            throw new \InvalidArgumentException('Invalid conflictMode.');
        }
        if ($mode === 'exclusive_group') {
            $this->string($definition['group'] ?? null, 'exclusive_group group');
        }
        $scope = $definition['scope'] ?? 'order';
        if (!in_array($scope, ['order', 'order_item'], true)) {
            throw new \InvalidArgumentException('Invalid rule scope.');
        }

        $this->recipient($this->object($definition['recipient'] ?? null, 'recipient'));
        $this->formula($this->object($definition['formula'] ?? null, 'formula'));
        if (isset($definition['eligibility'])) {
            $this->eligibility($this->object($definition['eligibility'], 'eligibility'));
        }
        foreach (['allocationKey', 'reasonCode'] as $field) {
            if (isset($definition[$field])) {
                $this->string($definition[$field], $field);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function recipient(array $config): void
    {
        $resolver = $config['resolver'] ?? null;
        match ($resolver) {
            'literal' => $this->literalRecipient($config),
            'context_candidate' => $this->string($config['key'] ?? null, 'recipient candidate key'),
            'fact_reference' => $this->factReferenceRecipient($config),
            default => throw new \InvalidArgumentException('Unknown recipient resolver.'),
        };
    }

    /** @param array<string, mixed> $node */
    private function eligibility(array $node): void
    {
        [$name, $arguments] = $this->node($node, 'eligibility');
        match ($name) {
            'all', 'any' => $this->eligibilityChildren($arguments, $name),
            'not' => $this->eligibility($this->object($arguments['child'] ?? $arguments[0] ?? null, 'not child')),
            'factEquals', 'factIn', 'intAtLeast', 'intAtMost', 'amountAtLeast', 'amountAtMost', 'occurredBefore', 'occurredAfter' => $this->factPredicate($arguments, $name),
            default => throw new \InvalidArgumentException("Unknown eligibility operation: $name"),
        };
    }

    /** @param array<mixed> $arguments */
    private function eligibilityChildren(array $arguments, string $name): void
    {
        $children = $arguments['children'] ?? $arguments;
        if (!is_array($children) || !array_is_list($children) || $children === []) {
            throw new \InvalidArgumentException("$name requires a non-empty children list.");
        }
        foreach ($children as $child) {
            $this->eligibility($this->object($child, "$name child"));
        }
    }

    /** @param array<mixed> $arguments */
    private function factPredicate(array $arguments, string $name): void
    {
        if (!array_key_exists(0, $arguments) || !array_key_exists(1, $arguments)) {
            throw new \InvalidArgumentException("$name requires [fact, value].");
        }
        $this->string($arguments[0], "$name fact");
        if ($name === 'factIn' && (!is_array($arguments[1]) || !array_is_list($arguments[1]))) {
            throw new \InvalidArgumentException('factIn requires a list value.');
        }
    }

    /** @param array<string, mixed> $node */
    private function formula(array $node): void
    {
        [$name, $arguments] = $this->node($node, 'formula');
        match ($name) {
            'fundingAmount' => null,
            'fixedAmount' => $this->string($arguments['amount'] ?? null, 'fixedAmount amount'),
            'factAmount' => $this->string($arguments['fact'] ?? null, 'factAmount fact'),
            'rateOf' => $this->rateOf($arguments),
            'multiplyByQuantity' => $this->multiply($arguments),
            'add', 'minOf', 'maxOf' => $this->formulaOperands($arguments, $name),
            'subtract' => $this->subtract($arguments),
            default => throw new \InvalidArgumentException("Unknown formula operation: $name"),
        };
    }

    /** @param array<mixed> $arguments */
    private function rateOf(array $arguments): void
    {
        $bps = $arguments['bps'] ?? null;
        if (!is_int($bps) || $bps < 0 || $bps > 10000) {
            throw new \InvalidArgumentException('rateOf bps must be an integer within 0..10000.');
        }
        $this->formulaInput($arguments['basis'] ?? null, 'rateOf basis');
    }

    /** @param array<mixed> $arguments */
    private function multiply(array $arguments): void
    {
        $this->formulaInput($arguments['value'] ?? null, 'multiplyByQuantity value');
        $quantity = $arguments['quantity'] ?? null;
        if (!is_int($quantity) && !is_string($quantity)) {
            throw new \InvalidArgumentException('multiplyByQuantity quantity must be an integer or fact name.');
        }
    }

    /** @param array<mixed> $arguments */
    private function formulaOperands(array $arguments, string $name): void
    {
        $operands = $arguments['operands'] ?? $arguments['terms'] ?? null;
        if (!is_array($operands) || !array_is_list($operands) || $operands === []) {
            throw new \InvalidArgumentException("$name requires a non-empty operands list.");
        }
        foreach ($operands as $operand) {
            $this->formulaInput($operand, "$name operand");
        }
    }

    private function formulaInput(mixed $input, string $name): void
    {
        if ($input === 'funding.distributable') {
            return;
        }
        $this->formula($this->object($input, $name));
    }

    /** @param array<string, mixed> $config */
    private function literalRecipient(array $config): void
    {
        $this->string($config['type'] ?? null, 'recipient type');
        $this->string($config['id'] ?? null, 'recipient id');
    }

    /** @param array<string, mixed> $config */
    private function factReferenceRecipient(array $config): void
    {
        $this->string($config['typeFact'] ?? null, 'recipient typeFact');
        $this->string($config['idFact'] ?? null, 'recipient idFact');
    }

    /** @param array<mixed> $arguments */
    private function subtract(array $arguments): void
    {
        $this->formulaInput($arguments['minuend'] ?? null, 'subtract minuend');
        $this->formulaInput($arguments['subtrahend'] ?? null, 'subtract subtrahend');
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: string, 1: array<mixed>}
     */
    private function node(array $node, string $kind): array
    {
        if (count($node) !== 1) {
            throw new \InvalidArgumentException("$kind must contain exactly one operation.");
        }
        $name = array_key_first($node);
        if (!is_string($name) || !is_array($node[$name])) {
            throw new \InvalidArgumentException("Invalid $kind node.");
        }
        return [$name, $node[$name]];
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("$name must be an object.");
        }
        return $value;
    }

    private function string(mixed $value, string $name): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("$name must be a non-empty string.");
        }
    }
}
