<?php

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\ExpressionService;
use PHPUnit\Framework\TestCase;

final class ExpressionServiceTest extends TestCase
{
    public function testBuildFilterUsesParsedAssemblerResult(): void
    {
        $service = new class extends ExpressionService {
            protected function parseAndAssemble(string $filter, string $dataClass, array $values, $em): array
            {
                $qb = new class {
                    public function getDQL(): string
                    {
                        return 'DQL_PLACEHOLDER';
                    }
                };

                $parameter = new class {
                    public function getName(): string
                    {
                        return 'p1';
                    }

                    public function getValue(): int
                    {
                        return 42;
                    }
                };

                return ['qb' => $qb, 'parameters' => [$parameter]];
            }
        };

        $result = $service->buildFilter('a==b', 'Entity', [], new \stdClass());

        self::assertArrayHasKey('qb', $result);
        self::assertArrayHasKey('parameters', $result);
        self::assertSame('p1', $result['parameters'][0]->getName());
        self::assertSame(42, $result['parameters'][0]->getValue());
    }
}
