<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use App\Core\Parser\ExpressionQueryBuilderAssembler;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the remaining branch combinations of ExpressionQueryBuilderAssembler
 * not exercised by ExpressionQueryBuilderAssemblerFullTest.
 */
#[AllowMockObjectsWithoutExpectations]
final class ExpressionQueryBuilderAssemblerCoverageTest extends TestCase
{
    private function parserMock(array $fragments): ExpressionDqlParser
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getFragments')->willReturn($fragments);
        $parser->method('getDataClass')->willReturn('App\Common\Entity\Content');
        $parser->method('getRootAlias')->willReturn('filter_entity');

        return $parser;
    }

    private function qbMock(): QueryBuilder
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getParameters')->willReturn(new ArrayCollection());

        return $qb;
    }

    #[Group('low-value')]
    public function testGetRootAliasesFailureFallsBackToEmptyAndAddsFrom(): void
    {
        $parser = $this->parserMock(['joins' => [], 'where' => '', 'params' => []]);

        $qb = $this->qbMock();
        $qb->method('getRootAliases')->willThrowException(new \RuntimeException('no roots'));
        $qb->method('getAllAliases')->willReturn(['filter_entity']);
        $qb->expects(self::once())
            ->method('from')
            ->with('App\Common\Entity\Content', 'filter_entity')
            ->willReturn($qb);
        $qb->method('select')->willReturn($qb);

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertSame($qb, $result);
    }

    #[Group('low-value')]
    public function testGetAllAliasesFailureFallsBackToRootAliases(): void
    {
        $parser = $this->parserMock([
            'joins' => ['filter_entity_category' => 'filter_entity.category'],
            'where' => '',
            'params' => [],
        ]);

        $qb = $this->qbMock();
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->method('getAllAliases')->willThrowException(new \RuntimeException('no aliases'));
        $qb->expects(self::once())
            ->method('leftJoin')
            ->with('e.category', 'filter_entity_category')
            ->willReturn($qb);

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertSame($qb, $result);
    }

    public function testDuplicateJoinAliasIsSkipped(): void
    {
        $parser = $this->parserMock([
            'joins' => ['filter_entity_category' => 'filter_entity.category'],
            'where' => '',
            'params' => [],
        ]);

        $qb = $this->qbMock();
        $qb->method('getRootAliases')->willReturn(['filter_entity']);
        $qb->method('getAllAliases')->willReturn(['filter_entity', 'filter_entity_category']);
        $qb->expects(self::never())->method('leftJoin');

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertSame($qb, $result);
    }

    #[Group('low-value')]
    public function testLeftJoinFailureIsSilentlyIgnored(): void
    {
        $parser = $this->parserMock([
            'joins' => ['filter_entity_category' => 'filter_entity.category'],
            'where' => '',
            'params' => [],
        ]);

        $qb = $this->qbMock();
        $qb->method('getRootAliases')->willReturn(['filter_entity']);
        $qb->method('getAllAliases')->willReturn(['filter_entity']);
        $qb->method('leftJoin')->willThrowException(new \RuntimeException('join boom'));

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertSame($qb, $result);
    }
}
