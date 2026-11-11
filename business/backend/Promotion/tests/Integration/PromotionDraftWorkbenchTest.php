<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use App\Promotion\Strategy\DiscountStrategy;
use App\Promotion\Strategy\FreeShippingStrategy;
use App\Promotion\Strategy\FullReductionStrategy;
use App\Promotion\Strategy\GiftStrategy;
use App\Promotion\Strategy\MemberDiscountStrategy;
use App\Promotion\Strategy\NthItemDiscountStrategy;
use NsUltimate\Business\Promotion\Service\PromotionDraftWorkbench;
use PHPUnit\Framework\TestCase;

final class PromotionDraftWorkbenchTest extends TestCase
{
    private function workbench(): PromotionDraftWorkbench
    {
        return new PromotionDraftWorkbench([new FullReductionStrategy(), new DiscountStrategy(), new GiftStrategy(), new NthItemDiscountStrategy(), new MemberDiscountStrategy(), new FreeShippingStrategy()]);
    }

    private function template(string $type = 'full_reduction', string $action = 'discount order config.amount'): array
    {
        return ['type' => $type, 'phase' => 0, 'dsl' => "type: $type\nphase: inner\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  $action\n"];
    }

    private function sample(int $price, int $expected): array
    {
        return ['items' => [['specificationId' => 1, 'quantity' => 1, 'unitPrice' => $price]], 'expected' => ['totalAmount' => $expected]];
    }

    public function testParameterizedThresholdAndAmountExecuteRealStrategies(): void
    {
        $result = $this->workbench()->inspect(['template' => $this->template(), 'config' => ['threshold' => 10000, 'amount' => 10],
            'cases' => [$this->sample(9999, 9999), $this->sample(10000, 9000), $this->sample(12000, 11000)]]);
        self::assertTrue($result['valid'], json_encode($result));
        self::assertTrue($result['passed']);
        self::assertSame('minor', $result['parameterSchema']['properties']['threshold']['unit']);
        self::assertSame('major', $result['parameterSchema']['properties']['amount']['unit']);
        self::assertFalse($result['cases'][0]['actual']['matched']);
        self::assertTrue($result['cases'][1]['actual']['matched']);
    }

    public function testMissingUnknownAndWrongParameterValuesAreStructuredErrors(): void
    {
        $result = $this->workbench()->inspect(['template' => $this->template(), 'config' => ['amount' => '10', 'thresholdd' => 100]]);
        self::assertFalse($result['valid']);
        self::assertSame(['missing_config', 'invalid_config', 'unknown_config'], array_column($result['errors'], 'code'));
    }

    public function testTemplateOnlyInfersContractAndChecksDeclarations(): void
    {
        $template = $this->template();
        $result = $this->workbench()->inspect(['template' => $template]);
        self::assertTrue($result['valid']);
        $template['fields'] = $result['parameterSchema'];
        self::assertTrue($this->workbench()->inspect(['template' => $template])['valid']);
        $template['fields']['properties']['threshold']['unit'] = 'major';
        self::assertContains('unit_mismatch', array_column($this->workbench()->inspect(['template' => $template])['errors'], 'code'));
    }

    public function testExpectedFailuresAreNotValidationSuccessAndMissingExpectationsAreNotProof(): void
    {
        $input = ['template' => $this->template(), 'config' => ['threshold' => 10000, 'amount' => 10], 'cases' => [$this->sample(10000, 10000)]];
        $result = $this->workbench()->inspect($input);
        self::assertTrue($result['valid']);
        self::assertFalse($result['passed']);
        self::assertSame(9000, $result['cases'][0]['differences'][0]['actual']);
        unset($input['cases'][0]['expected']);
        self::assertFalse($this->workbench()->inspect($input)['passed']);
    }

    public function testWrongStrategyAndMisleadingItemConditionsAreRejected(): void
    {
        $template = $this->template('discount', 'add gift spec 12 count 1');
        self::assertContains('action_type', array_column($this->workbench()->inspect(['template' => $template])['errors'], 'code'));
        $template = $this->template();
        $template['dsl'] = str_replace('cart.subtotal', 'item.price', $template['dsl']);
        self::assertContains('unsupported_path', array_column($this->workbench()->inspect(['template' => $template])['errors'], 'code'));
    }

    public function testItemsDiscountRequiresTargetsAndDiscountsOnlyMatchingGroups(): void
    {
        $template = $this->template('discount', 'discount items config.rate%');
        $result = $this->workbench()->inspect(['template' => $template, 'config' => ['threshold' => 0, 'rate' => 90, 'specification_ids' => [1]], 'cases' => [[
            'items' => [['specificationId' => 1, 'quantity' => 2, 'unitPrice' => 1000], ['specificationId' => 2, 'quantity' => 1, 'unitPrice' => 1000]], 'expected' => ['totalAmount' => 2800],
        ]]]);
        self::assertTrue($result['passed'], json_encode($result));
        self::assertSame(1800, $result['cases'][0]['actual']['items'][0]['price']);
        self::assertSame(1000, $result['cases'][0]['actual']['items'][1]['price']);
    }

    public function testMemberNthGiftAndShippingVariants(): void
    {
        foreach ([
            ['member_discount', 'member discount 90%', ['min_level' => 'gold'], 'gold', 9000],
            ['member_discount', 'member discount 90%', ['min_level' => 'gold'], 'silver', 10000],
            ['nth_discount', 'discount item 2 50%', [], null, 7500],
            ['gift', 'add gift spec 12 count 1', [], null, 10000],
            ['free_shipping', 'free shipping', [], null, 10000],
        ] as [$type, $action, $config, $level, $total]) {
            $case = ['items' => [['specificationId' => 1, 'quantity' => 2, 'unitPrice' => 5000]], 'memberLevel' => $level, 'expected' => ['totalAmount' => $total]];
            $result = $this->workbench()->inspect(['template' => $this->template($type, $action), 'config' => ['threshold' => 0] + $config, 'cases' => [$case]]);
            self::assertTrue($result['passed'], json_encode($result));
            if ($type === 'gift') self::assertSame(12, $result['cases'][0]['actual']['items'][1]['specificationId']);
            if ($type === 'free_shipping') self::assertTrue($result['cases'][0]['actual']['freeShipping']);
        }
    }

    public function testMalformedAndOversizedSamplesFailWithoutExecuting(): void
    {
        $result = $this->workbench()->inspect(['template' => $this->template(), 'config' => ['threshold' => 0, 'amount' => 10], 'cases' => [['items' => [['unitPrice' => -1]]]]]);
        self::assertFalse($result['valid']);
        self::assertSame('invalid_case', $result['errors'][0]['code']);
        self::assertFalse($this->workbench()->inspect(['template' => $this->template(), 'config' => [], 'cases' => array_fill(0, 9, [])])['valid']);
    }
}
