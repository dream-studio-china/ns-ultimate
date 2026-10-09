<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Strategy;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\MemberDiscountStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class MemberDiscountStrategyTest extends TestCase
{
    private MemberDiscountStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new MemberDiscountStrategy();
    }

    private function createUserWithLevel(string $level): User
    {
        $user = new User();
        $profile = new Profile($user, $level);
        (new \ReflectionClass(User::class))->getProperty('profile')->setValue($user, $profile);
        return $user;
    }

    public function testSupportedType(): void
    {
        self::assertSame('member_discount', MemberDiscountStrategy::supportedType());
    }

    public function testApplyWithMatchingLevel(): void
    {
        $user = $this->createUserWithLevel(Profile::LEVEL_GOLD);

        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->user = $user;

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => Profile::LEVEL_SILVER]);

        // 10% off: 50000 * (100-90) / 100 = 5000, new total = 45000
        self::assertSame(45000, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testApplyWithDiamondLevel(): void
    {
        $user = $this->createUserWithLevel(Profile::LEVEL_DIAMOND);

        $context = new PriceCalculationContext([]);
        $context->totalAmount = 100000;
        $context->user = $user;

        $action = new AstNode('action_member_discount', ['rate' => 80]);

        $this->strategy->apply($action, $context, ['min_level' => Profile::LEVEL_GOLD]);

        // 20% off: 100000 * 20 / 100 = 20000, new total = 80000
        self::assertSame(80000, $context->totalAmount);
    }

    public function testApplyWithLowerLevelThanMin(): void
    {
        $user = $this->createUserWithLevel(Profile::LEVEL_BRONZE);

        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->user = $user;

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => Profile::LEVEL_GOLD]);

        // User is bronze, min is gold — no discount
        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithoutUser(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->user = null;

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => Profile::LEVEL_SILVER]);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithoutProfile(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->user = new User();

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => Profile::LEVEL_SILVER]);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithNonUserObject(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->user = new \stdClass();

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        $this->strategy->apply($action, $context, ['min_level' => Profile::LEVEL_GOLD]);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithDefaultMinLevel(): void
    {
        $user = $this->createUserWithLevel(Profile::LEVEL_BRONZE);

        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->user = $user;

        $action = new AstNode('action_member_discount', ['rate' => 90]);

        // Default min_level = 'bronze'
        $this->strategy->apply($action, $context, []);

        // 10% off: 50000 * 10 / 100 = 5000, new total = 45000
        self::assertSame(45000, $context->totalAmount);
    }
}
