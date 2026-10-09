<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Core\Parser;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Parser\ExpressionDqlParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidatorException;

#[Group('low-value')]
final class ExpressionDqlParserXTest extends TestCase
{
    public function testCompileWithLessThan(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() < 50');
        $parser->compile();

        self::assertStringContainsString('<', $parser->getWhere());
        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileWithTernaryComparison(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1 && entity.getTitle() == "ok"');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertStringContainsString('AND', $where);
        self::assertCount(2, $parser->getParameters());
    }

    public function testCompileWithOrComparison(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() < 10 || entity.getId() > 100');
        $parser->compile();

        self::assertStringContainsString('OR', $parser->getWhere());
        self::assertCount(2, $parser->getParameters());
    }

    public function testCompileWithTwoLevelNestedRelation(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getParent().getId() == 1');
        $parser->compile();

        self::assertNotEmpty($parser->getJoins());
        self::assertCount(1, $parser->getParameters());
    }

    public function testValidateFragmentsDoesNotThrow(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $metadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('hasAssociation')->willReturn(false);

        $parser->validateFragments($em);
        self::assertTrue(true);
    }

    public function testGetSourceWithQueryBuilder(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $source = $parser->getSource(null);
        self::assertStringContainsString('SELECT filter_entity', $source);
        self::assertStringContainsString('FROM App\Common\Entity\Content', $source);
    }

    public function testGetFragmentsBeforeCompile(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');

        $fragments = $parser->getFragments();
        self::assertArrayHasKey('where', $fragments);
        self::assertArrayHasKey('joins', $fragments);
        self::assertArrayHasKey('params', $fragments);
        self::assertSame('', $fragments['where']);
        self::assertSame([], $fragments['joins']);
    }

    public function testGetParametersArrayEmptyBeforeCompile(): void
    {
        $parser = new ExpressionDqlParser();
        self::assertCount(0, $parser->getParameters());
    }
}
