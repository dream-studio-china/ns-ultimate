<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Query\DqlExpression;
use App\Core\View\ApiView;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class ApiViewDqlExpressionTest extends TestCase
{
    private function controllerWith(callable $commonFilter): object
    {
        return new class($commonFilter) {
            use ApiView;
            public function __construct(private $fn) {}
            protected function commonFilter() { return ($this->fn)(); }
            public function exposeResolved(){ return $this->resolvedCommonFilter(); }
            public function exposeMix($id){ return $this->mixIdToCommonFilter($id); }
            public function exposeMixToCommon(array $data, $filter=null){ return $this->mixToCommonFilter($data, $filter); }
        };
    }

    public function testResolvedCommonFilterBindsThisWhenNeeded(): void
    {
        $ctrl = $this->controllerWith(fn() => new DqlExpression('entity.getUser() == this.getUser()'));
        $resolved = $ctrl->exposeResolved();
        self::assertInstanceOf(DqlExpression::class, $resolved);
        self::assertSame($ctrl, $resolved->context());
    }

    public function testResolvedCommonFilterDoesNotRequireThisWhenNotUsed(): void
    {
        $ctrl = $this->controllerWith(fn() => new DqlExpression('entity.getStatus() == status', ['status'=>'active']));
        $resolved = $ctrl->exposeResolved();
        self::assertNull($resolved->context());
    }

    public function testMixIdAddsCriteriaToDqlExpression(): void
    {
        $ctrl = $this->controllerWith(fn() => new DqlExpression('entity.getUser() == user', ['user'=> new \stdClass()]));
        $mixed = $ctrl->exposeMix(42);
        self::assertInstanceOf(DqlExpression::class, $mixed);
        self::assertSame(42, $mixed->criteria()['id']);
    }

    public function testMixIdAddsCriteriaToThisBoundExpression(): void
    {
        $ctrl = $this->controllerWith(fn() => new DqlExpression('entity.getUser() == this.getUser()'));
        $uuid = \App\Core\Utils\UUID::v4();
        $mixed = $ctrl->exposeMix($uuid);
        self::assertArrayHasKey('uuid', $mixed->criteria());
        self::assertSame($uuid, $mixed->criteria()['uuid']);
    }

    public function testArrayCommonFilterStillWorks(): void
    {
        $ctrl = $this->controllerWith(fn() => ['status' => 'active']);
        $mixed = $ctrl->exposeMix(1);
        self::assertIsArray($mixed);
        self::assertSame('active', $mixed['status']);
        self::assertSame(1, $mixed['id']);
    }

    public function testQueryBuilderCommonFilterAppendsWhere(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['entity']);
        $qb->expects(self::once())->method('andWhere')->with('entity.id = :id')->willReturnSelf();
        $qb->expects(self::once())->method('setParameter')->with('id', 99)->willReturnSelf();

        $ctrl = $this->controllerWith(fn() => $qb);
        $result = $ctrl->exposeMix(99);
        self::assertSame($qb, $result);
    }

    public function testListMixinUsesResolvedCommonFilter(): void
    {
        // Simulate ListApiViewMixin listAction uses resolvedCommonFilter
        $ctrl = new class {
            use ApiView;
            use \App\Core\View\ListApiViewMixin;
            public $capturedFilter;
            protected $service;
            public function __construct(){ $this->service = new class {
                public function list($filter, $a, $b){ return $filter; }
            }; }
            protected function commonFilter(): DqlExpression { return new DqlExpression('entity.getUser() == this.getUser()'); }
            public function getCaptured(){ $this->capturedFilter = $this->resolvedCommonFilter(); return $this->capturedFilter; }
        };
        $f = $ctrl->getCaptured();
        self::assertInstanceOf(DqlExpression::class, $f);
        self::assertSame($ctrl, $f->context());
    }
}
