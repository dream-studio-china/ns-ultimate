<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service\Dsl;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\Evaluator;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

/**
 * Covers the remaining uncovered lines of Evaluator.php:
 *   - line 124  resolveOperand() with a null operand
 *   - line 168  user.level path resolution against a real User
 *   - line 171  user.tags path resolution against an object exposing getTags()
 */
final class EvaluatorCoverageTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new Evaluator();
    }

    private function context(): PriceCalculationContext
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        return $context;
    }

    public function testNullOperandIsResolvedToNull(): void
    {
        $cond = new AstNode('condition', [
            'op' => '==',
            'left' => null,
            'right' => null,
        ]);

        $result = $this->evaluator->evaluateCondition($cond, $this->context(), []);

        // null == null is true in PHP loose comparison
        self::assertTrue($result);
    }

    public function testNullOperandWithUnknownOperatorReturnsFalse(): void
    {
        $cond = new AstNode('condition', [
            'op' => 'unknown_op',
            'left' => null,
            'right' => null,
        ]);

        $result = $this->evaluator->evaluateCondition($cond, $this->context(), []);

        self::assertFalse($result);
    }

    public function testUserIdResolvedFromRealUser(): void
    {
        $context = $this->context();
        $user = new class extends User {
            public function getId(): ?int
            {
                return 42;
            }
        };
        $context->user = $user;

        $cond = new AstNode('condition', [
            'op' => '==',
            'left' => new AstNode('path', ['value' => 'user.id']),
            'right' => new AstNode('literal', ['value' => 42]),
        ]);

        self::assertTrue($this->evaluator->evaluateCondition($cond, $context, []));
    }

    public function testUserLevelWithoutProfileReturnsEmptyString(): void
    {
        $context = $this->context();
        $context->user = new User();

        $cond = new AstNode('condition', [
            'op' => '==',
            'left' => new AstNode('path', ['value' => 'user.level']),
            'right' => new AstNode('literal', ['value' => '']),
        ]);

        self::assertTrue($this->evaluator->evaluateCondition($cond, $context, []));
    }

    public function testUserLevelWithProfileReturnsProfileLevel(): void
    {
        $context = $this->context();
        $user = new User();
        $profile = new Profile($user, 'gold');
        $user->setProfile($profile);
        $context->user = $user;

        $cond = new AstNode('condition', [
            'op' => '==',
            'left' => new AstNode('path', ['value' => 'user.level']),
            'right' => new AstNode('literal', ['value' => 'gold']),
        ]);

        self::assertTrue($this->evaluator->evaluateCondition($cond, $context, []));
    }

    public function testUserTagsReturnedFromObjectWithGetTags(): void
    {
        $context = $this->context();
        $context->user = new class extends User {
            public function getTags(): array
            {
                return ['vip', 'new'];
            }
        };

        $cond = new AstNode('condition', [
            'op' => 'includes',
            'left' => new AstNode('path', ['value' => 'user.tags']),
            'right' => new AstNode('literal', ['value' => 'vip']),
        ]);

        self::assertTrue($this->evaluator->evaluateCondition($cond, $context, []));
    }

    public function testUserTagsMissingTagReturnsFalse(): void
    {
        $context = $this->context();
        $context->user = new class extends User {
            public function getTags(): array
            {
                return ['vip', 'new'];
            }
        };

        $cond = new AstNode('condition', [
            'op' => 'includes',
            'left' => new AstNode('path', ['value' => 'user.tags']),
            'right' => new AstNode('literal', ['value' => 'absent']),
        ]);

        self::assertFalse($this->evaluator->evaluateCondition($cond, $context, []));
    }
}
