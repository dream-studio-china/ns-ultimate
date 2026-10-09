<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Settlement\Contract\AllocationProposal;
use App\Settlement\Contract\ComputedAllocation;
use App\Settlement\Contract\RecipientReference;
use App\Settlement\Contract\SettlementContext;
use App\Settlement\Contract\SettlementItemContext;
use App\Settlement\Entity\SettlementRuleVersion;
use App\Settlement\Service\Money\AllocationRoundingService;
use App\Settlement\Service\Money\QuantumAmount;
use Brick\Math\BigInteger;

/** Evaluates the closed settlement-rule JSON grammar against a frozen context. */
final class SettlementRuleEngine
{
    public function __construct(private readonly AllocationRoundingService $rounding)
    {
    }

    /**
     * @param list<SettlementRuleVersion> $versions
     * @return list<ComputedAllocation>
     */
    public function evaluate(
        SettlementContext $context,
        array $versions,
        int $postingScale,
        string $fundingQuantum,
        string $fallbackType = 'platform',
        string $fallbackId = 'default',
    ): array {
        if ($postingScale < 0 || $postingScale > $context->calculationScale) {
            throw new \DomainException('postingScale must be within [0, calculationScale]');
        }

        $proposals = $this->collectProposals($context, $versions);
        $exactByKey = [];
        $exactTotal = BigInteger::zero();
        foreach ($proposals as $proposal) {
            if (isset($exactByKey[$proposal->allocationKey])) {
                throw new \DomainException("Duplicate allocation key: {$proposal->allocationKey}");
            }
            $exactByKey[$proposal->allocationKey] = $proposal->exactAmountQuantum;
            $exactTotal = $exactTotal->plus($proposal->exactAmountQuantum);
        }

        $funding = BigInteger::of($fundingQuantum);
        if ($exactTotal->isGreaterThan($funding)) {
            throw new \DomainException('Allocation exact total exceeds funding amount');
        }
        if ($exactTotal->isLessThan($funding)) {
            if (isset($exactByKey['fallback'])) {
                throw new \DomainException('Rule allocation key "fallback" is reserved');
            }
            $remainder = $funding->minus($exactTotal)->toBase(10);
            $proposals[] = new AllocationProposal(
                allocationKey: 'fallback',
                recipient: new RecipientReference($fallbackType, $fallbackId),
                exactAmountQuantum: $remainder,
                calculationScale: $context->calculationScale,
                currency: $context->currency,
                ruleCode: 'fallback',
                ruleVersionUuid: null,
                reasonCode: 'fallback_remainder',
                recipientSnapshot: ['source' => 'engine_fallback'],
                calculationEvidence: ['fundingQuantum' => $fundingQuantum],
            );
            $exactByKey['fallback'] = $remainder;
        }

        $postedByKey = $this->rounding->distribute($exactByKey, $fundingQuantum, $context->calculationScale, $postingScale);
        $ranks = $this->ranks($exactByKey, $context->calculationScale, $postingScale);
        $factor = BigInteger::of(10)->power($this->factorExponent($context->calculationScale, $postingScale));
        $computed = [];

        foreach ($proposals as $proposal) {
            $posted = BigInteger::of($postedByKey[$proposal->allocationKey]);
            $exact = BigInteger::of($proposal->exactAmountQuantum);
            $computed[] = new ComputedAllocation(
                allocationKey: $proposal->allocationKey,
                recipient: $proposal->recipient,
                exactAmountQuantum: $proposal->exactAmountQuantum,
                postingAmount: $posted->toBase(10),
                postingScale: $postingScale,
                roundingDeltaQuantum: $exact->minus($posted->multipliedBy($factor))->toBase(10),
                roundingRank: $ranks[$proposal->allocationKey] ?? null,
                ruleCode: $proposal->ruleCode,
                ruleVersionUuid: $proposal->ruleVersionUuid,
                reasonCode: $proposal->reasonCode,
                recipientSnapshot: $proposal->recipientSnapshot,
                evidence: $proposal->calculationEvidence,
                sourceItemId: $proposal->sourceItemId,
                sourceItemSnapshot: $proposal->sourceItemSnapshot,
            );
        }

        $postedTotal = BigInteger::zero();
        foreach ($computed as $allocation) {
            $postedTotal = $postedTotal->plus($allocation->postingAmount);
        }
        if (!$postedTotal->isEqualTo($funding->quotient($factor))) {
            throw new \DomainException('Posting amounts do not conserve the funding total');
        }

        return $computed;
    }

    /**
     * @param list<SettlementRuleVersion> $versions
     * @return list<AllocationProposal>
     */
    private function collectProposals(SettlementContext $context, array $versions): array
    {
        $proposals = $this->collectScopeProposals($context, $versions, 'order');
        foreach ($context->items as $item) {
            $proposals = array_merge($proposals, $this->collectScopeProposals($context, $versions, 'order_item', $item));
        }

        return $proposals;
    }

    /**
     * @param list<SettlementRuleVersion> $versions
     * @return list<AllocationProposal>
     */
    private function collectScopeProposals(
        SettlementContext $context,
        array $versions,
        string $scope,
        ?SettlementItemContext $item = null,
    ): array {
        $evaluationContext = $item === null ? $context : $context->forItem($item);
        $proposals = [];
        $exclusiveGroups = [];
        foreach ($versions as $version) {
            $definition = $version->getDefinition();
            if (($definition['scope'] ?? 'order') !== $scope) {
                continue;
            }
            if (!$this->appliesTo($definition, $evaluationContext->subject->type)) {
                continue;
            }
            $mode = $definition['conflictMode'] ?? 'stack';
            $group = $mode === 'exclusive_group' ? ($definition['group'] ?? null) : null;
            if (is_string($group) && isset($exclusiveGroups[$group])) {
                continue;
            }
            $eligibility = $definition['eligibility'] ?? null;
            if ($eligibility !== null && (!is_array($eligibility) || !$this->eligible($evaluationContext, $eligibility))) {
                continue;
            }

            $recipient = $this->recipient($evaluationContext, $this->array($definition['recipient'] ?? null, 'recipient'));
            $amount = $this->formula($evaluationContext, $this->array($definition['formula'] ?? null, 'formula'));
            $code = (string) ($definition['code'] ?? $version->getRuleUuid());
            $allocationKey = (string) ($definition['allocationKey'] ?? "$code.{$recipient->asString()}");
            if ($item !== null) {
                $allocationKey = $this->itemAllocationKey($allocationKey, $item->id);
            }
            $proposals[] = new AllocationProposal(
                allocationKey: $allocationKey,
                recipient: $recipient,
                exactAmountQuantum: $amount,
                calculationScale: $evaluationContext->calculationScale,
                currency: $evaluationContext->currency,
                ruleCode: $code,
                ruleVersionUuid: $version->getUuid(),
                reasonCode: (string) ($definition['reasonCode'] ?? 'rule'),
                recipientSnapshot: ['recipient' => $definition['recipient']],
                calculationEvidence: array_filter([
                    'formula' => $definition['formula'],
                    'scope' => $scope,
                    'sourceItemId' => $item?->id,
                ], static fn (mixed $value): bool => $value !== null),
                sourceItemId: $item?->id,
                sourceItemSnapshot: $item?->snapshot,
            );
            if (is_string($group)) {
                $exclusiveGroups[$group] = true;
            }
            if ($mode === 'stop') {
                break;
            }
        }
        return $proposals;
    }

    private function itemAllocationKey(string $base, string $itemId): string
    {
        $key = $base . '.' . $itemId;
        if (strlen($key) <= 128) {
            return $key;
        }

        return substr($key, 0, 63) . '.' . hash('sha256', $key);
    }

    /** @param array<string, mixed> $definition */
    private function appliesTo(array $definition, string $subjectType): bool
    {
        return isset($definition['appliesTo']) && is_array($definition['appliesTo'])
            && in_array($subjectType, $definition['appliesTo'], true);
    }

    /** @param array<string, mixed> $node */
    private function eligible(SettlementContext $context, array $node): bool
    {
        [$operation, $arguments] = $this->node($node, 'eligibility');
        return match ($operation) {
            'all' => $this->all($context, $arguments),
            'any' => $this->any($context, $arguments),
            'not' => !$this->eligible($context, $this->child($arguments, 'not')),
            'factEquals' => $this->fact($context, $this->argument($arguments, 0, $operation)) === $this->argument($arguments, 1, $operation),
            'factIn' => in_array($this->fact($context, $this->argument($arguments, 0, $operation)), $this->arrayArgument($arguments, 1, $operation), true),
            'intAtLeast' => $this->integerFact($context, $this->argument($arguments, 0, $operation)) >= $this->integer($this->argument($arguments, 1, $operation), $operation),
            'intAtMost' => $this->integerFact($context, $this->argument($arguments, 0, $operation)) <= $this->integer($this->argument($arguments, 1, $operation), $operation),
            'amountAtLeast' => $this->amountFact($context, $this->argument($arguments, 0, $operation))->bigInteger()->isGreaterThanOrEqualTo($this->amount($context, $this->argument($arguments, 1, $operation))->bigInteger()),
            'amountAtMost' => $this->amountFact($context, $this->argument($arguments, 0, $operation))->bigInteger()->isLessThanOrEqualTo($this->amount($context, $this->argument($arguments, 1, $operation))->bigInteger()),
            'occurredBefore' => $this->dateFact($context, $this->argument($arguments, 0, $operation)) < $this->date($this->argument($arguments, 1, $operation)),
            'occurredAfter' => $this->dateFact($context, $this->argument($arguments, 0, $operation)) > $this->date($this->argument($arguments, 1, $operation)),
            default => throw new \InvalidArgumentException("Unknown eligibility operation: $operation"),
        };
    }

    /** @param array<string, mixed>|list<mixed> $arguments */
    private function all(SettlementContext $context, array $arguments): bool
    {
        foreach ($this->children($arguments, 'all') as $child) {
            if (!$this->eligible($context, $child)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed>|list<mixed> $arguments */
    private function any(SettlementContext $context, array $arguments): bool
    {
        foreach ($this->children($arguments, 'any') as $child) {
            if ($this->eligible($context, $child)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $config */
    private function recipient(SettlementContext $context, array $config): RecipientReference
    {
        $resolver = $config['resolver'] ?? null;
        return match ($resolver) {
            'literal' => new RecipientReference($this->string($config['type'] ?? null, 'literal recipient type'), $this->string($config['id'] ?? null, 'literal recipient id')),
            'context_candidate' => $this->candidate($context, $this->string($config['key'] ?? null, 'context candidate key')),
            'fact_reference' => new RecipientReference(
                $this->string($this->fact($context, $this->string($config['typeFact'] ?? null, 'typeFact')), 'recipient type fact'),
                $this->string($this->fact($context, $this->string($config['idFact'] ?? null, 'idFact')), 'recipient id fact'),
            ),
            default => throw new \InvalidArgumentException('Unknown recipient resolver'),
        };
    }

    /** @param array<string, mixed> $node */
    private function formula(SettlementContext $context, array $node): string
    {
        [$operation, $arguments] = $this->node($node, 'formula');
        $amount = match ($operation) {
            'fundingAmount' => BigInteger::of($context->distributableAmountQuantum),
            'fixedAmount' => $this->amount($context, $this->named($arguments, 'amount', $operation))->bigInteger(),
            'factAmount' => $this->amountFact($context, $this->named($arguments, 'fact', $operation))->bigInteger(),
            'rateOf' => $this->rateOf($context, $arguments),
            'multiplyByQuantity' => $this->multiply($context, $arguments),
            'add' => $this->add($context, $arguments),
            'subtract' => $this->subtract($context, $arguments),
            'minOf' => $this->extreme($context, $arguments, true),
            'maxOf' => $this->extreme($context, $arguments, false),
            default => throw new \InvalidArgumentException("Unknown formula operation: $operation"),
        };
        if ($amount->isLessThan(BigInteger::zero())) {
            throw new \DomainException('Formula produced a negative amount');
        }
        return $amount->toBase(10);
    }

    /** @param array<string, mixed>|list<mixed> $arguments */
    private function rateOf(SettlementContext $context, array $arguments): BigInteger
    {
        $bps = $this->integer($this->named($arguments, 'bps', 'rateOf'), 'rateOf bps');
        if ($bps < 0 || $bps > 10000) {
            throw new \DomainException('rateOf bps must be within 0..10000');
        }
        return BigInteger::of($this->formulaInput($context, $this->named($arguments, 'basis', 'rateOf')))->multipliedBy($bps)->quotient(10000);
    }

    /** @param array<string, mixed>|list<mixed> $arguments */
    private function multiply(SettlementContext $context, array $arguments): BigInteger
    {
        $quantity = $this->named($arguments, 'quantity', 'multiplyByQuantity');
        if (is_string($quantity) && $context->hasFact($quantity)) {
            $quantity = $context->fact($quantity);
        }
        return BigInteger::of($this->formulaInput($context, $this->named($arguments, 'value', 'multiplyByQuantity')))
            ->multipliedBy($this->integer($quantity, 'multiplyByQuantity quantity'));
    }

    /** @param array<string, mixed>|list<mixed> $arguments */
    private function add(SettlementContext $context, array $arguments): BigInteger
    {
        $sum = BigInteger::zero();
        foreach ($this->operands($arguments, 'add') as $operand) {
            $sum = $sum->plus($this->formulaInput($context, $operand));
        }
        return $sum;
    }

    /** @param array<string, mixed>|list<mixed> $arguments */
    private function subtract(SettlementContext $context, array $arguments): BigInteger
    {
        return BigInteger::of($this->formulaInput($context, $this->named($arguments, 'minuend', 'subtract')))
            ->minus($this->formulaInput($context, $this->named($arguments, 'subtrahend', 'subtract')));
    }

    /** @param array<string, mixed>|list<mixed> $arguments */
    private function extreme(SettlementContext $context, array $arguments, bool $minimum): BigInteger
    {
        $result = null;
        foreach ($this->operands($arguments, $minimum ? 'minOf' : 'maxOf') as $operand) {
            $value = BigInteger::of($this->formulaInput($context, $operand));
            if ($result === null || ($minimum ? $value->isLessThan($result) : $value->isGreaterThan($result))) {
                $result = $value;
            }
        }
        return $result ?? throw new \InvalidArgumentException('Formula requires at least one operand');
    }

    private function formulaInput(SettlementContext $context, mixed $input): string
    {
        if ($input === 'funding.distributable') {
            return $context->distributableAmountQuantum;
        }
        return $this->formula($context, $this->array($input, 'formula operand'));
    }

    /**
     * @param array<string, string> $exactByKey
     * @return array<string, int>
     */
    private function ranks(array $exactByKey, int $scale, int $postingScale): array
    {
        $factor = BigInteger::of(10)->power($this->factorExponent($scale, $postingScale));
        $remainders = [];
        foreach ($exactByKey as $key => $exact) {
            $amount = BigInteger::of($exact);
            $remainders[$key] = $amount->minus($amount->quotient($factor)->multipliedBy($factor));
        }
        uksort($remainders, static fn (string $a, string $b): int => $remainders[$b]->compareTo($remainders[$a]) ?: strcmp($a, $b));
        $ranks = [];
        foreach ($remainders as $key => $remainder) {
            if ($remainder->isGreaterThan(BigInteger::zero())) {
                $ranks[$key] = count($ranks) + 1;
            }
        }
        return $ranks;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: string, 1: array<mixed>}
     */
    private function node(array $node, string $kind): array
    {
        if (count($node) !== 1) {
            throw new \InvalidArgumentException("$kind must contain exactly one operation");
        }
        $name = array_key_first($node);
        if (!is_string($name) || !is_array($node[$name])) {
            throw new \InvalidArgumentException("Invalid $kind node");
        }
        return [$name, $node[$name]];
    }

    /**
     * @param array<string, mixed>|list<mixed> $arguments
     * @return list<array<mixed>>
     */
    private function children(array $arguments, string $operation): array
    {
        $children = $arguments['children'] ?? $arguments;
        if (!is_array($children) || !array_is_list($children)) {
            throw new \InvalidArgumentException("$operation requires children");
        }
        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new \InvalidArgumentException("$operation children must be nodes");
            }
        }
        return $children;
    }

    /**
     * @param array<string, mixed>|list<mixed> $arguments
     * @return array<mixed>
     */
    private function child(array $arguments, string $operation): array
    {
        return $this->array($arguments['child'] ?? $arguments[0] ?? null, "$operation child");
    }

    /**
     * @param array<string, mixed>|list<mixed> $arguments
     */
    private function argument(array $arguments, int $index, string $operation): mixed
    {
        if (!array_key_exists($index, $arguments)) {
            throw new \InvalidArgumentException("$operation requires argument $index");
        }
        return $arguments[$index];
    }

    /**
     * @param array<string, mixed>|list<mixed> $arguments
     */
    private function named(array $arguments, string $name, string $operation): mixed
    {
        if (!array_key_exists($name, $arguments)) {
            throw new \InvalidArgumentException("$operation requires $name");
        }
        return $arguments[$name];
    }

    /**
     * @param array<string, mixed>|list<mixed> $arguments
     * @return list<mixed>
     */
    private function operands(array $arguments, string $operation): array
    {
        $operands = $arguments['operands'] ?? $arguments['terms'] ?? null;
        if (!is_array($operands) || !array_is_list($operands)) {
            throw new \InvalidArgumentException("$operation requires operands");
        }
        return $operands;
    }

    /**
     * @param array<string, mixed>|list<mixed> $arguments
     * @return array<mixed>
     */
    private function arrayArgument(array $arguments, int $index, string $operation): array
    {
        return $this->array($this->argument($arguments, $index, $operation), "$operation argument $index");
    }

    private function fact(SettlementContext $context, mixed $name): mixed
    {
        $name = $this->string($name, 'fact name');
        if (!$context->hasFact($name)) {
            throw new \InvalidArgumentException("Unknown context fact: $name");
        }
        return $context->fact($name);
    }

    private function integerFact(SettlementContext $context, mixed $name): int
    {
        return $this->integer($this->fact($context, $name), 'integer fact');
    }

    private function amountFact(SettlementContext $context, mixed $name): QuantumAmount
    {
        return $this->amount($context, $this->fact($context, $name));
    }

    private function amount(SettlementContext $context, mixed $decimal): QuantumAmount
    {
        return QuantumAmount::fromDecimal($this->string($decimal, 'amount'), $context->currency, $context->calculationScale);
    }

    private function dateFact(SettlementContext $context, mixed $name): \DateTimeImmutable
    {
        return $this->date($this->fact($context, $name));
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($this->string($value, 'date'));
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid date fact');
        }
    }

    private function candidate(SettlementContext $context, string $key): RecipientReference
    {
        return $context->recipientCandidates[$key] ?? throw new \InvalidArgumentException("Unknown recipient candidate: $key");
    }

    private function integer(mixed $value, string $name): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return (int) $value;
        }
        throw new \InvalidArgumentException("$name must be an integer");
    }

    private function string(mixed $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("$name must be a non-empty string");
        }
        return $value;
    }

    /** @return array<mixed> */
    private function array(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("$name must be an object");
        }
        return $value;
    }

    /** @return int<0, max> */
    private function factorExponent(int $scale, int $postingScale): int
    {
        return max(0, $scale - $postingScale);
    }
}
