<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Trade\Entity\Order;
use NsUltimate\Business\Printer\Service\OrderPrintRuleMatcher;
use PHPUnit\Framework\TestCase;

final class OrderPrintRuleMatcherTest extends TestCase
{
    public function testAllConditionsUseStrictTypedMetadataAndEvent(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID)->setMetadata(['counterSettlement' => true, 'nested' => ['value' => null]]);
        $rule = ['enabled' => true, 'events' => ['paid', 'accepted'], 'match' => 'all', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'paid'],
            ['field' => 'metadata.counterSettlement', 'operator' => 'equals', 'value' => true],
            ['field' => 'metadata.nested.value', 'operator' => 'exists'],
        ]];
        $matcher = new OrderPrintRuleMatcher();
        self::assertTrue($matcher->matches($rule, 'paid', $order));
        self::assertFalse($matcher->matches($rule, 'confirmed', $order));
        $rule['conditions'][1]['value'] = 'true';
        self::assertFalse($matcher->matches($rule, 'paid', $order));
    }

    public function testAnyAndMissingFields(): void
    {
        $matcher = new OrderPrintRuleMatcher();
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $rule = ['enabled' => true, 'events' => ['confirmed'], 'match' => 'any', 'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'paid'],
            ['field' => 'metadata.missing', 'operator' => 'not_exists'],
        ]];
        self::assertTrue($matcher->matches($rule, 'confirmed', $order));
        $rule['conditions'][1]['operator'] = 'not_equals';
        $rule['conditions'][1]['value'] = true;
        self::assertFalse($matcher->matches($rule, 'confirmed', $order));
    }

    public function testMalformedAndDisabledRulesFailClosed(): void
    {
        $matcher = new OrderPrintRuleMatcher();
        $order = new Order();
        $rule = ['enabled' => true, 'events' => ['paid'], 'match' => 'all', 'conditions' => []];
        self::assertTrue($matcher->matches($rule, 'paid', $order));
        $rule['enabled'] = false;
        self::assertFalse($matcher->matches($rule, 'paid', $order));
        $rule['enabled'] = true;
        $rule['conditions'] = [['field' => 'metadata.bad..path', 'operator' => 'exists']];
        self::assertFalse($matcher->matches($rule, 'paid', $order));
        $rule['conditions'] = array_fill(0, 21, ['field' => 'status', 'operator' => 'exists']);
        self::assertFalse($matcher->matches($rule, 'paid', $order));
        $rule['conditions'] = [];
        $rule['events'] = ['paid', ['invalid']];
        self::assertFalse($matcher->matches($rule, 'paid', $order));
        $rule['events'] = ['paid'];
        $rule['match'] = 'any';
        self::assertFalse($matcher->matches($rule, 'paid', $order));
    }

    public function testNonScalarMetadataDoesNotMatchScalarComparisons(): void
    {
        $matcher = new OrderPrintRuleMatcher();
        $order = (new Order())->setMetadata(['nested' => ['value' => ['unexpected']]]);
        $rule = ['enabled' => true, 'events' => ['paid'], 'match' => 'all', 'conditions' => [
            ['field' => 'metadata.nested.value', 'operator' => 'not_equals', 'value' => 'safe'],
        ]];
        self::assertFalse($matcher->matches($rule, 'paid', $order));
    }
}
