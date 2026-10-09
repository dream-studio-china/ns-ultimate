<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Symfony\Component\Validator\Exception\ValidatorException;

final class ExpressionDqlParserTest extends TestCase
{
    public function testCompileSimpleExpressionCreatesWhereAndParameters(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertNotSame('', $parser->getWhere());
        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileEmptyExpressionThrows(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');

        $this->expectException(ValidatorException::class);
        $parser->setExpression('')->compile();
    }

    public function testTopLevelAttributeAddsIsNotNull(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getOwner().getId()');
        $parser->compile();

        self::assertStringContainsString('IS NOT NULL', $parser->getWhere());
    }

    public function testCompileWithCacheHitReturnsCachedResult(): void
    {
        $cachedJoins = ['filter_entity_user' => 'filter_entity.user'];
        $cachedWhere = 'filter_entity.status = :p1';
        $cachedParams = ['filter_parameter_1' => 'active'];

        $cache = $this->createMock(SimpleCacheInterface::class);
        $cache->method('has')->willReturn(true);
        $cache->method('get')->willReturn([
            'joins' => $cachedJoins,
            'where' => $cachedWhere,
            'params' => $cachedParams,
        ]);

        $parser = new ExpressionDqlParser($cache);
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getStatus() == "active"');
        $parser->compile();

        self::assertSame($cachedWhere, $parser->getWhere());
        self::assertSame($cachedJoins, $parser->getJoins());
    }

    public function testCompileWithCacheWritesAfterCompile(): void
    {
        $cache = $this->createMock(SimpleCacheInterface::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())->method('set');

        $parser = new ExpressionDqlParser($cache);
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertNotSame('', $parser->getWhere());
    }

    public function testCompileSyntaxErrorThrowsValidatorException(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId( == 1');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessageMatches('/Expression syntax error:/');
        $parser->compile();
    }

    public function testCompileWithNegationOperator(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('!entity.getTitle()');
        $parser->compile();

        self::assertStringContainsString('IS NULL', $parser->getWhere());
    }

    public function testGetSourceWithQueryBuilderUsesDql(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getDQL')->willReturn('SELECT e FROM App\Entity\Content e WHERE e.id = 1');

        $source = $parser->getSource($qb);

        self::assertSame('SELECT e FROM App\Entity\Content e WHERE e.id = 1', $source);
    }

    public function testGetSourceWithoutDataClassThrows(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Data class is not set');
        $parser->getSource(null);
    }

    public function testGetSourceWithoutQueryBuilderBuildsManualDql(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $source = $parser->getSource(null);

        self::assertStringContainsString('SELECT filter_entity FROM', $source);
        self::assertStringContainsString('WHERE', $source);
        self::assertStringNotContainsString('Data class is not set', $source);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateFragmentsWithValidFragmentsSucceeds(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('hasField')->willReturn(true);
        $meta->method('hasAssociation')->willReturn(false);
        $em->method('getClassMetadata')->willReturn($meta);

        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $parser->validateFragments($em);

        self::assertTrue(true);
    }

    public function testResetClearsJoinsAndWhere(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertNotSame('', $parser->getWhere());

        $parser->reset();

        self::assertSame('', $parser->getWhere());
        self::assertSame([], $parser->getJoins());
    }

    public function testGetRootAliasReturnsFilterEntity(): void
    {
        $parser = new ExpressionDqlParser();

        self::assertSame('filter_entity', $parser->getRootAlias());
    }

    public function testGetFragmentsReturnsStructuredArray(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $fragments = $parser->getFragments();

        self::assertIsArray($fragments);
        self::assertArrayHasKey('joins', $fragments);
        self::assertArrayHasKey('where', $fragments);
        self::assertArrayHasKey('params', $fragments);
    }

    public function testGetParametersArrayReturnsAssociativeArray(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $params = $parser->getParametersArray();

        self::assertIsArray($params);
        self::assertNotEmpty($params);
    }

    public function testSetterMethodsReturnSelf(): void
    {
        $parser = new ExpressionDqlParser();

        $result = $parser->setExpression('entity.getId() == 1');
        self::assertSame($parser, $result);

        $result = $parser->setDataClass('App\\Common\\Entity\\Content');
        self::assertSame($parser, $result);

        $result = $parser->setValues(['entity' => '']);
        self::assertSame($parser, $result);
    }

    public function testGetDataClassReturnsCorrectValue(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');

        self::assertSame('App\\Common\\Entity\\Content', $parser->getDataClass());
    }

    public function testCompileWithLogicOperatorOnGetAttrNodes(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() && entity.getBody()');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertStringContainsString('IS NOT NULL', $where);
        self::assertStringContainsString('AND', $where);
    }

    public function testCompileWithOrLogicAndAttrs(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() || entity.getBody()');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertStringContainsString('OR', $where);
        self::assertStringContainsString('IS NOT NULL', $where);
    }

    public function testCompileWithCacheReadExceptionStillCompiles(): void
    {
        $cache = $this->createMock(SimpleCacheInterface::class);
        $cache->method('has')->willThrowException(
            new class extends \Exception implements \Psr\SimpleCache\InvalidArgumentException {}
        );

        $parser = new ExpressionDqlParser($cache);
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertNotSame('', $parser->getWhere());
    }

    public function testCompileWithExpressionThatHasNonEntityKeyAutoAddsSignature(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['customKey' => 'someValue']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertNotSame('', $parser->getWhere());
    }
}

