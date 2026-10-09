<?php

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\LegacyEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class LegacyEvaluatorTest extends TestCase
{
    public function testEvaluateWithGlobalsAndContext(): void
    {
        $evaluator = new LegacyEvaluator(new ExpressionLanguage(), null, ['a' => 10]);

        self::assertSame(15, $evaluator->evaluate('a + b', ['b' => 5]));
        self::assertTrue($evaluator->evaluateBool('a + b == 15', ['b' => 5]));
    }

    public function testEvaluateReturnsFalseWhenExpressionInvalid(): void
    {
        $evaluator = new LegacyEvaluator(new ExpressionLanguage(), new NullLogger());

        self::assertFalse($evaluator->evaluate('1 +', []));
    }
}
