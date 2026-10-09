<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * Covers the remaining branches of ExpressionDqlParser not exercised by the
 * existing ExpressionDqlParserTest / FullTest / MatchesTest / XTest suites.
 */
#[AllowMockObjectsWithoutExpectations]
final class ExpressionDqlParserCoverageTest extends TestCase
{
    private function newParser(): ExpressionDqlParser
    {
        return (new ExpressionDqlParser())
            ->setDataClass('App\Common\Entity\Content')
            ->setValues(['entity' => '']);
    }

    #[Group('low-value')]
    public function testCompileWithoutSetValuesAutoAddsEntitySignature(): void
    {
        // setValues() was never called => $this->names is empty, signature must be auto-added.
        $parser = (new ExpressionDqlParser())
            ->setDataClass('App\Common\Entity\Content')
            ->setExpression('entity.getId() == 1');

        $parser->compile();

        self::assertStringContainsString('filter_entity.id = :filter_parameter_1', $parser->getWhere());
    }

    public function testParseThrowingGenericExceptionIsWrappedInValidatorException(): void
    {
        $language = $this->createMock(ExpressionLanguage::class);
        $language->method('parse')->willThrowException(new \RuntimeException('parse boom'));

        $parser = new ExpressionDqlParser(null, $language);
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Expression parse error: parse boom');
        $parser->compile();
    }

    public function testCompileWhenNodesAccessThrowsGenericExceptionIsWrapped(): void
    {
        $parsed = $this->createMock(ParsedExpression::class);
        $parsed->method('getNodes')->willThrowException(new \RuntimeException('node boom'));

        $language = $this->createMock(ExpressionLanguage::class);
        $language->method('parse')->willReturn($parsed);

        $parser = new ExpressionDqlParser(null, $language);
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Expression compile error: node boom');
        $parser->compile();
    }

    public function testCacheSetFailureIsSilentlyIgnored(): void
    {
        $cache = $this->createMock(SimpleCacheInterface::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willThrowException(new \RuntimeException('cache down'));

        $parser = new ExpressionDqlParser($cache);
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertStringContainsString('filter_entity.id = :filter_parameter_1', $parser->getWhere());
    }

    public function testUnsupportedBinaryOperatorThrows(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getId() ** 2');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Unsupported operator: **');
        $parser->compile();
    }

    public function testUnsupportedUnaryOperatorThrows(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getId() == -5');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Unsupported unary operator');
        $parser->compile();
    }

    public function testArrayNodeIsTraversedViaGenericBranch(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getId() == [1, 2]');
        $parser->compile();

        self::assertStringContainsString('filter_entity.id = ', $parser->getWhere());
        // [1, 2] now compiles as a single array parameter via ArrayNode handling.
        self::assertSame(1, $parser->getParameters()->count());
        self::assertSame([1, 2], $parser->getParametersArray()['filter_parameter_1']);
    }

    public function testGetSourceFallsBackToManualDqlWhenQueryBuilderDqlThrows(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getDQL')->willThrowException(new \RuntimeException('dql boom'));

        $source = $parser->getSource($qb);

        self::assertStringContainsString('SELECT filter_entity FROM App\Common\Entity\Content filter_entity', $source);
        self::assertStringContainsString('WHERE (filter_entity.id = :filter_parameter_1)', $source);
    }

    #[Group('low-value')]
    public function testValidateFragmentsRejectsUnknownAliasInWhere(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $reflection = new \ReflectionClass(ExpressionDqlParser::class);
        $whereProperty = $reflection->getProperty('where');
        $whereProperty->setValue($parser, 'some_unknown_alias.field = :filter_parameter_1');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Unknown alias "some_unknown_alias" in path "some_unknown_alias.field"');

        $parser->validateFragments($this->createMock(EntityManagerInterface::class));
    }

    #[Group('low-value')]
    public function testValidateFragmentsSkipsEmptyJoinPath(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $reflection = new \ReflectionClass(ExpressionDqlParser::class);
        $joinsProperty = $reflection->getProperty('joins');
        $joinsProperty->setValue($parser, ['filter_entity_empty' => '']);

        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('hasAssociation')->willReturn(false);
        $em->method('getClassMetadata')->willReturn($metadata);

        $parser->validateFragments($em);

        self::assertTrue(true);
    }
}
