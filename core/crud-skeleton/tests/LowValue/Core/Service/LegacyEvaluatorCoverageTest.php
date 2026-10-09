<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Core\Service;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Service\LegacyEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('low-value')]
final class LegacyEvaluatorCoverageTest extends TestCase
{
    public function testEvaluateReturnsFalseWhenLanguageIsNull(): void
    {
        $evaluator = new class extends LegacyEvaluator {
            public function __construct()
            {
                parent::__construct(null, new NullLogger());
                // Simulate the language having been destroyed/unset at runtime.
                $this->language = null;
            }
        };

        self::assertFalse($evaluator->evaluate('1 + 1'));
        self::assertFalse($evaluator->evaluateBool('true'));
    }
}
