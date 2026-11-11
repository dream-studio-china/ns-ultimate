<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Service;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use App\Promotion\Strategy\PromotionStrategyInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Bounded, non-persistent authoring checks; never a replacement pricing engine. */
final class PromotionDraftWorkbench
{
    private const ACTIONS = [
        'full_reduction' => 'action_discount', 'discount' => 'action_discount',
        'nth_discount' => 'action_discount', 'member_discount' => 'action_member_discount',
        'gift' => 'action_gift', 'free_shipping' => 'action_free_shipping',
    ];
    private const LEVELS = ['bronze', 'silver', 'gold', 'platinum', 'diamond'];

    /** @param iterable<PromotionStrategyInterface> $strategies */
    public function __construct(#[AutowireIterator('promotion.strategy')] private readonly iterable $strategies) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function inspect(array $input): array
    {
        $issue = new DraftDiagnostics();
        $template = $input['template'] ?? null;
        if (!is_array($template) || array_is_list($template)) return $this->failure('invalid_template', 'template', 'Provide a template object.');
        if (strlen((string) json_encode([$template, $input['config'] ?? []])) > 32000) return $this->failure('draft_limit', 'template', 'Template and configuration exceed the authoring size limit.');
        $type = $template['type'] ?? '';
        $phase = $template['phase'] ?? 0;
        $dsl = $template['dsl'] ?? '';
        if (!is_string($type) || !isset(self::ACTIONS[$type])) $issue->add('unsupported_type', 'template.type', 'This authoring type is not supported by the current engine.');
        if (!in_array($phase, [0, 1], true)) $issue->add('invalid_phase', 'template.phase', 'Use 0 (inner) or 1 (outer).');
        if (!is_string($dsl) || trim($dsl) === '' || strlen($dsl) > 16000 || substr_count($dsl, "\n") > 200) $issue->add('invalid_dsl', 'template.dsl', 'DSL must contain 1-16000 bytes and at most 200 lines.');
        if ($issue->hasErrors()) return ['valid' => false, 'errors' => $issue->all()];
        try {
            $ast = (new Parser())->parse((new Lexer())->tokenize($dsl));
        } catch (DslSyntaxException $error) {
            return ['valid' => false, 'errors' => [['code' => 'syntax', 'path' => 'template.dsl', 'message' => $error->getMessage(), 'line' => $error->line, 'col' => $error->col]]];
        }
        if (($ast->data['type'] ?? null) !== $type) $issue->add('type_mismatch', 'template.type', 'The DSL must declare the same type.');
        if (($ast->data['phase'] ?? 0) !== $phase) $issue->add('phase_mismatch', 'template.phase', 'The DSL phase must match the template phase.');
        if (preg_match_all('/^[ \t]*phase:([^\r\n]*)/m', $dsl, $phases)) foreach ($phases[1] as $value) {
            if (!in_array(trim(explode('#', $value, 2)[0]), ['inner', 'outer'], true)) $issue->add('invalid_phase', 'template.dsl', 'Use an exact inner or outer phase header.');
        }
        $sections = [];
        foreach ($ast->children as $child) {
            if (isset($sections[$child->type])) $issue->add('duplicate_section', 'template.dsl', 'Repeated sections are not supported.');
            $sections[$child->type] = $child;
        }
        $actions = $sections['do']->children ?? [];
        if ($actions === []) $issue->add('missing_action', 'template.dsl', 'A non-empty do section is required.');
        if (isset($sections['when']) && $sections['when']->children === []) $issue->add('empty_condition', 'template.dsl', 'Omit when or supply at least one condition.');
        if (isset($sections['fields'])) $issue->add('legacy_fields', 'template.dsl', 'Use the template.fields JSON contract instead of DSL field declarations.');
        if (preg_match_all('/^\s*(type|phase|priority):/m', $dsl, $headers) && count($headers[1]) !== count(array_unique($headers[1]))) $issue->add('duplicate_header', 'template.dsl', 'Repeated headers are not supported.');

        $requirements = [];
        $require = static function (string $key, array $rule) use (&$requirements, $issue): void {
            if (!preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $key) || preg_match('/password|secret|token|credential|private_key|api_key/i', $key) || in_array($key, ['constructor', 'prototype', '__proto__'], true)) {
                $issue->add('invalid_parameter', 'config', 'Parameter names must be safe lowercase identifiers.');
                return;
            }
            if (isset($requirements[$key]) && $requirements[$key] !== $rule) $issue->add('conflicting_parameter', 'config.'.$key, 'A parameter cannot have incompatible usages or units.');
            $requirements[$key] = $rule;
        };
        $walkCondition = function (AstNode $node, int $depth = 0) use (&$walkCondition, $issue, $require): void {
            if ($depth > 8) { $issue->add('condition_depth', 'template.dsl', 'Conditions are too deeply nested.'); return; }
            if ($node->type !== 'condition') {
                // Indentation does not delimit logical blocks in the upstream parser.
                if ($depth > 0 && in_array($node->type, ['and', 'or', 'not'], true)) $issue->add('ambiguous_logic', 'template.dsl', 'Nested logical blocks are not reliably scoped by this engine.');
                foreach ($node->children as $child) $walkCondition($child, $depth + 1);
                return;
            }
            $operands = [$node->data['left'], $node->data['right']];
            if (in_array($node->data['op'], ['in', 'includes'], true)) $issue->add('unsupported_operator', 'template.dsl', 'Set conditions are not supported by this bounded authoring contract; use strategy specification targets.');
            foreach ($operands as $operand) {
                if (!$operand instanceof AstNode || $operand->type !== 'path') continue;
                $path = $operand->data['value'];
                if (!str_starts_with($path, 'config.') && !in_array($path, ['cart.subtotal', 'cart.items.count', 'user.level'], true)) $issue->add('unsupported_path', 'template.dsl', 'Unsupported or misleading condition path: '.$path.'. item paths only inspect the first item.');
                if ($path === 'user.level' && !in_array($node->data['op'], ['==', '!='], true)) $issue->add('level_comparison', 'template.dsl', 'Member levels are not ordered strings; use the member_discount strategy.');
                if (str_starts_with($path, 'config.')) {
                    $other = $operands[0] === $operand ? $operands[1] : $operands[0];
                    $source = $other instanceof AstNode && $other->type === 'path' ? $other->data['value'] : '';
                    $rule = match ($source) {
                        'cart.subtotal' => ['type' => 'integer', 'minimum' => 0, 'unit' => 'minor'],
                        'cart.items.count' => ['type' => 'integer', 'minimum' => 0, 'unit' => 'item_groups'],
                        'user.level' => ['type' => 'string', 'enum' => self::LEVELS],
                        default => $other instanceof AstNode && $other->type === 'literal' && is_string($other->data['value'] ?? null) ? ['type' => 'string'] : ['type' => 'number'],
                    };
                    $require(substr($path, 7), $rule);
                }
            }
        };
        foreach ($sections['when']->children ?? [] as $condition) $walkCondition($condition);
        foreach ($actions as $index => $action) {
            $path = 'template.dsl.actions.'.$index;
            if ($action->type !== self::ACTIONS[$type]) { $issue->add('action_type', $path, 'The selected strategy cannot execute this action.'); continue; }
            $data = $action->data;
            if ($type === 'full_reduction' && (($data['target'] ?? '') !== 'order' || isset($data['isPercent']))) $issue->add('action_target', $path, 'Full reduction requires a fixed order amount.');
            if ($type === 'discount' && (!in_array($data['target'] ?? '', ['order', 'items'], true) || empty($data['isPercent']))) $issue->add('action_target', $path, 'Discount requires order/items and an explicit percentage.');
            if ($type === 'nth_discount' && (($data['target'] ?? '') !== 'item' || ($data['position'] ?? 0) < 1 || empty($data['isPercent']))) $issue->add('action_target', $path, 'Nth discount requires a positive position and explicit percentage.');
            if (in_array($type, ['full_reduction', 'discount', 'nth_discount', 'member_discount'], true)) {
                $value = $data['rate'] ?? $data['value'] ?? null;
                $rule = $type === 'full_reduction' ? ['type' => 'number', 'minimum' => 0, 'maximum' => 1000000, 'unit' => 'major'] : ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'percent_kept'];
                if (is_string($value) && str_starts_with($value, 'config.')) $require(substr($value, 7), $rule);
                elseif (!$this->matches($rule, $value)) $issue->add('invalid_action_value', $path, 'Amount/rate is missing or outside the allowed range.');
                if (isset($data['maxCap']) && !$this->matches(['type' => 'number', 'minimum' => 0, 'maximum' => 1000000], $data['maxCap'])) $issue->add('invalid_cap', $path, 'The discount cap must be a non-negative major-unit amount.');
            }
            if ($type === 'discount' && ($data['target'] ?? '') === 'items') $require('specification_ids', ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => 100]);
            if ($type === 'member_discount') $require('min_level', ['type' => 'string', 'enum' => self::LEVELS]);
            if ($type === 'gift' && (!$this->matches(['type' => 'integer', 'minimum' => 1], $data['spec'] ?? null) || !$this->matches(['type' => 'integer', 'minimum' => 1, 'maximum' => 99], $data['count'] ?? null))) $issue->add('invalid_gift', $path, 'Gift spec and count must be positive integer literals; config references are not reliably parsed.');
        }
        $priority = $ast->data['priority']['value'] ?? null;
        if (is_string($priority) && str_starts_with($priority, 'config.')) {
            $key = substr($priority, 7);
            if (!isset($requirements[$key])) $require($key, ['type' => 'number']);
        }
        $fields = $template['fields'] ?? null;
        if (count($requirements) > 32) $issue->add('parameter_limit', 'template.dsl', 'At most 32 consumed parameters are supported.');
        if ($fields !== null) {
            if (!is_array($fields) || array_diff(array_keys($fields), ['type', 'title', 'description', 'properties', 'required', 'additionalProperties']) !== [] || ($fields['type'] ?? null) !== 'object' || ($fields['additionalProperties'] ?? null) !== false || !is_array($fields['properties'] ?? null)) $issue->add('invalid_contract', 'template.fields', 'Use a bounded object schema with properties and additionalProperties:false.');
            else {
                foreach ($fields['properties'] as $key => $rule) {
                    if (!isset($requirements[$key])) { $issue->add('unused_parameter', 'template.fields.properties.'.$key, 'This parameter is not consumed by the DSL or strategy.'); continue; }
                    if (!$this->validRule($rule) || ($rule['type'] ?? null) !== $requirements[$key]['type']) {
                        $issue->add('invalid_contract', 'template.fields.properties.'.$key, 'Unsupported contract keywords or a type inconsistent with usage.');
                        continue;
                    }
                    if (isset($requirements[$key]['unit']) && ($rule['unit'] ?? null) !== $requirements[$key]['unit']) $issue->add('unit_mismatch', 'template.fields.properties.'.$key, 'Declare unit '.$requirements[$key]['unit'].'.');
                }
                foreach ($requirements as $key => $rule) if (!array_key_exists($key, $fields['properties'])) $issue->add('undeclared_parameter', 'template.fields.properties.'.$key, 'Declare every DSL/strategy parameter.');
                $required = $fields['required'] ?? null;
                if (!is_array($required) || !array_is_list($required) || array_filter($required, static fn ($key) => !is_string($key)) !== []
                    || array_diff(array_keys($requirements), $required) !== [] || array_diff($required, array_keys($requirements)) !== []) $issue->add('required_parameters', 'template.fields.required', 'All consumed parameters must be required.');
            }
        }
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $requirements, 'required' => array_keys($requirements)];
        if (array_key_exists('config', $input)) {
            $config = $input['config'];
            if (!is_array($config) || ($config !== [] && array_is_list($config)) || count($config) > 32) $issue->add('invalid_config', 'config', 'Use a parameter object with at most 32 keys.');
            else {
                foreach ($requirements as $key => $rule) {
                    if (!array_key_exists($key, $config)) $issue->add('missing_config', 'config.'.$key, 'Required template parameter is missing.');
                    elseif (!$this->matches($rule, $config[$key]) || (isset($fields['properties'][$key]) && $this->validRule($fields['properties'][$key]) && !$this->matches($fields['properties'][$key], $config[$key]))) $issue->add('invalid_config', 'config.'.$key, 'Value violates its type, range, enum or unit contract.');
                }
                foreach ($config as $key => $value) if (!isset($requirements[$key])) $issue->add('unknown_config', 'config.'.$key, 'The DSL and strategy do not consume this parameter.');
            }
        }
        $result = ['valid' => !$issue->hasErrors(), 'errors' => $issue->all(), 'parameterSchema' => $schema,
            'scope' => 'single_rule', 'warnings' => ['Cart item count means item groups, not summed quantities.', 'Samples do not verify catalog existence, real prices, campaign eligibility or stacking.']];
        if (!$issue->hasErrors() && $fields !== null) $result['declaredParameterSchema'] = $fields;
        if ($issue->hasErrors() || !array_key_exists('cases', $input)) return $result;
        if (!array_key_exists('config', $input)) return $this->failure('missing_config', 'config', 'Provide config explicitly for simulation.');
        $cases = $input['cases'];
        if (!is_array($cases) || !array_is_list($cases) || count($cases) < 1 || count($cases) > 8) return $this->failure('invalid_cases', 'cases', 'Provide 1-8 sample cases.');
        $evaluator = new Evaluator(is_array($this->strategies) ? $this->strategies : iterator_to_array($this->strategies, false));
        $results = [];
        foreach ($cases as $index => $case) {
            try { $results[] = $this->simulate($ast, $type, $input['config'], $case, $evaluator); }
            catch (\InvalidArgumentException $error) { $issue->add('invalid_case', 'cases.'.$index, $error->getMessage()); }
        }
        return array_replace($result, ['valid' => !$issue->hasErrors(), 'errors' => $issue->all(), 'cases' => $results,
            'passed' => !$issue->hasErrors() && !in_array(false, array_column($results, 'passed'), true) && !in_array(null, array_column($results, 'passed'), true)]);
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function simulate(AstNode $ast, string $type, array $config, mixed $case, Evaluator $evaluator): array
    {
        if (!is_array($case) || array_diff(array_keys($case), ['name', 'items', 'memberLevel', 'expected']) !== []) throw new \InvalidArgumentException('Unsupported sample fields.');
        $items = $case['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 20) throw new \InvalidArgumentException('Provide 1-20 sample item groups.');
        $context = new PriceCalculationContext([]);
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['specificationId', 'quantity', 'unitPrice']) !== []) throw new \InvalidArgumentException('Items accept specificationId, quantity and unitPrice only.');
            foreach (['specificationId' => [1, 2147483647], 'quantity' => [1, 1000], 'unitPrice' => [0, 100000000]] as $key => [$min, $max]) {
                if (!$this->matches(['type' => 'integer', 'minimum' => $min, 'maximum' => $max], $item[$key] ?? null)) throw new \InvalidArgumentException('Invalid '.$key.'; prices use integer minor units.');
            }
            $item['price'] = $item['quantity'] * $item['unitPrice'];
            $context->totalAmount += $item['price'];
            $context->items[] = $item;
        }
        $level = $case['memberLevel'] ?? null;
        if ($level !== null) {
            if (!in_array($level, self::LEVELS, true)) throw new \InvalidArgumentException('Invalid memberLevel.');
            $user = new User();
            $context->user = $user->setProfile(new Profile($user, $level));
        }
        $before = $context->totalAmount;
        $matched = true;
        $trace = [];
        foreach ($ast->children as $section) if ($section->type === 'when') foreach ($section->children as $condition) {
            $passed = $evaluator->evaluateCondition($condition, $context, $config);
            $trace[] = ['condition' => $condition, 'matched' => $passed];
            $matched = $matched && $passed;
        }
        if ($matched) foreach ($ast->children as $section) if ($section->type === 'do') $evaluator->executeActions($section->children, $type, $context, $config);
        $actual = ['matched' => $matched, 'totalAmount' => $context->totalAmount, 'items' => $context->items, 'freeShipping' => $context->meta['freeShipping'] ?? false];
        $expected = $case['expected'] ?? null;
        $differences = [];
        if ($expected !== null) {
            if (!is_array($expected) || $expected === [] || array_diff(array_keys($expected), ['matched', 'totalAmount', 'freeShipping', 'items']) !== []) throw new \InvalidArgumentException('Expected must contain supported actual-result fields.');
            foreach ($expected as $key => $value) if ($actual[$key] !== $value) $differences[] = ['path' => $key, 'expected' => $value, 'actual' => $actual[$key]];
        }
        return ['name' => is_string($case['name'] ?? null) ? substr($case['name'], 0, 80) : 'Sample', 'beforeAmount' => $before,
            'actual' => $actual, 'trace' => $trace, 'passed' => $expected === null ? null : $differences === [], 'differences' => $differences];
    }

    private function validRule(mixed $rule, int $depth = 0): bool
    {
        if (!is_array($rule) || $depth > 1 || array_diff(array_keys($rule), ['type', 'title', 'description', 'unit', 'minimum', 'maximum', 'enum', 'items', 'minItems', 'maxItems']) !== []
            || !in_array($rule['type'] ?? null, ['integer', 'number', 'string', 'array'], true)) return false;
        foreach (['minimum', 'maximum', 'minItems', 'maxItems'] as $key) if (isset($rule[$key]) && (!is_int($rule[$key]) && !is_float($rule[$key]) || !is_finite((float) $rule[$key]))) return false;
        if (isset($rule['minimum'], $rule['maximum']) && $rule['minimum'] > $rule['maximum']) return false;
        foreach (['minItems', 'maxItems'] as $key) if (isset($rule[$key]) && (!is_int($rule[$key]) || $rule[$key] < 0 || $rule[$key] > 1000)) return false;
        if (isset($rule['minItems'], $rule['maxItems']) && $rule['minItems'] > $rule['maxItems']) return false;
        if ($rule['type'] !== 'array' && array_intersect(array_keys($rule), ['items', 'minItems', 'maxItems']) !== []) return false;
        if (!in_array($rule['type'], ['integer', 'number'], true) && array_intersect(array_keys($rule), ['minimum', 'maximum']) !== []) return false;
        foreach (['title', 'description', 'unit'] as $key) if (isset($rule[$key]) && (!is_string($rule[$key]) || strlen($rule[$key]) > 500)) return false;
        if (isset($rule['enum']) && (!is_array($rule['enum']) || !array_is_list($rule['enum']) || count($rule['enum']) > 100 || array_filter($rule['enum'], static fn ($value) => !is_scalar($value)) !== [])) return false;
        return $rule['type'] !== 'array' || $this->validRule($rule['items'] ?? null, $depth + 1);
    }

    /** @param array<string, mixed> $rule */
    private function matches(array $rule, mixed $value): bool
    {
        $ok = match ($rule['type'] ?? '') {
            'integer' => is_int($value), 'number' => is_int($value) || is_float($value) && is_finite($value),
            'string' => is_string($value) && strlen($value) <= 120,
            'array' => is_array($value) && array_is_list($value), default => false,
        };
        if (!$ok || isset($rule['enum']) && (!is_array($rule['enum']) || !in_array($value, $rule['enum'], true))) return false;
        if (is_numeric($value) && (isset($rule['minimum']) && $value < $rule['minimum'] || isset($rule['maximum']) && $value > $rule['maximum'])) return false;
        if (is_array($value)) {
            if (count($value) < ($rule['minItems'] ?? 0) || count($value) > ($rule['maxItems'] ?? 100)) return false;
            foreach ($value as $item) if (!$this->matches($rule['items'] ?? [], $item)) return false;
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function failure(string $code, string $path, string $message): array
    {
        return ['valid' => false, 'errors' => [compact('code', 'path', 'message')]];
    }
}
