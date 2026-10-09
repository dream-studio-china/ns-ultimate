<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Query;

use App\Core\Query\DqlExpression;
use PHPUnit\Framework\TestCase;

final class DqlExpressionTest extends TestCase
{
    public function testEmptyExpressionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DqlExpression('');
    }

    public function testWhitespaceExpressionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DqlExpression('   ');
    }

    public function testReservedEntityVariableIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DqlExpression('entity.getId() == 1', ['entity' => 'x']);
    }

    public function testReservedThisVariableIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DqlExpression('entity.getId() == 1', ['this' => new \stdClass()]);
    }

    public function testInvalidVariableNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DqlExpression('entity.getId() == 1', ['bad-name' => 1]);
    }

    public function testInvalidCriteriaKeyIsRejected(): void
    {
        $d = new DqlExpression('entity.getId() == 1');
        $this->expectException(\InvalidArgumentException::class);
        $d->withCriteria(['' => 1]);
    }

    public function testDuplicateCriteriaKeyIsRejected(): void
    {
        $d = new DqlExpression('entity.getId() == 1');
        $d2 = $d->withCriteria(['id' => 1]);
        $this->expectException(\LogicException::class);
        $d2->withCriteria(['id' => 2]);
    }

    public function testCriteriaCollidingWithVariableIsRejected(): void
    {
        $d = new DqlExpression('entity.getId() == user', ['user' => new \stdClass()]);
        $this->expectException(\LogicException::class);
        $d->withCriteria(['user' => 1]);
    }

    public function testWithContextIsIdempotentForSameObject(): void
    {
        $c = new \stdClass();
        $d = new DqlExpression('entity.getUser() == this.getUser()');
        $d2 = $d->withContext($c);
        $d3 = $d2->withContext($c);
        self::assertSame($d2, $d3);
    }

    public function testWithContextRejectsDifferentObject(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $d = (new DqlExpression('entity.getUser() == this.getUser()'))->withContext($a);
        $this->expectException(\LogicException::class);
        $d->withContext($b);
    }

    public function testWithCriteriaIsImmutable(): void
    {
        $d = new DqlExpression('entity.getUser() == user', ['user' => 1]);
        $d2 = $d->withCriteria(['id' => 42]);
        self::assertNotSame($d, $d2);
        self::assertSame([], $d->criteria());
        self::assertSame(['id' => 42], $d2->criteria());
    }

    public function testUsesThisDetection(): void
    {
        self::assertFalse((new DqlExpression('entity.getUser() == user', ['user' => 1]))->usesThis());
        self::assertTrue((new DqlExpression('entity.getUser() == this.getUser()'))->usesThis());
    }

    public function testValidConstruction(): void
    {
        $d = new DqlExpression('entity.getUser() == user', ['user' => new \stdClass()]);
        self::assertSame('entity.getUser() == user', $d->expression);
        self::assertArrayHasKey('user', $d->values);
    }
}
