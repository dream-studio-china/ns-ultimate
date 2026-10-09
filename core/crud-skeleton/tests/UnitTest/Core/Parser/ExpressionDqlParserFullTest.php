<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidatorException;

final class ExpressionDqlParserFullTest extends TestCase
{
    public function testCompileWithNotEqual(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() != 1');
        $parser->compile();

        self::assertStringContainsString('!=', $parser->getWhere());
    }

    public function testCompileWithGreaterThan(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() > 10');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertNotSame('', $where);
    }

    public function testCompileWithLessThanOrEqual(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() <= 100');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertNotSame('', $where);
    }

    public function testCompileWithLogicalAnd(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() > 0 && entity.getId() < 100');
        $parser->compile();

        self::assertStringContainsString('AND', $parser->getWhere());
    }

    public function testCompileWithLogicalOr(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1 || entity.getId() == 2');
        $parser->compile();

        self::assertStringContainsString('OR', $parser->getWhere());
    }

    public function testCompileWithAdditionOperator(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() + 1 == 5');
        $parser->compile();

        self::assertCount(2, $parser->getParameters());
    }

    public function testCompileWithStringComparison(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() == "hello"');
        $parser->compile();

        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileWithNestedRelation(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getId() == 5');
        $parser->compile();

        self::assertNotEmpty($parser->getJoins());
        self::assertCount(1, $parser->getParameters());
    }

    #[Group('low-value')]
    public function testCompileWithMatchesRegex(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() matches "/test.*/ig"');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertStringContainsString('REGEXP', $where);
        self::assertSame(['filter_parameter_1' => '(?i)test.*'], $parser->getParametersArray());
    }

    #[Group('low-value')]
    public function testCompileWithMatchesPlainText(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() matches "50%_off!"');
        $parser->compile();

        self::assertStringContainsString("LIKE :filter_parameter_1 ESCAPE '!'", $parser->getWhere());
        self::assertStringNotContainsString('REGEXP', $parser->getWhere());
        self::assertSame(['filter_parameter_1' => '%50!%!_off!!%'], $parser->getParametersArray());
    }

    #[Group('low-value')]
    public function testCompileWithUnsupportedMatchesRegexFlagThrows(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() matches "/test/y"');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Unsupported matches regex flags: y');
        $parser->compile();
    }

    public function testCompileWithNotOperator(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('!(entity.getId() == 1)');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertNotSame('', $where);
    }

    public function testCompileWithBooleanTrue(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getEnabled() == true');
        $parser->compile();

        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileWithBooleanFalse(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getEnabled() == false');
        $parser->compile();

        self::assertCount(1, $parser->getParameters());
    }

    public function testSetValuesDoesNotThrow(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setValues(['entity' => 'test']);
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertStringContainsString('=', $parser->getWhere());
    }

    public function testRootAliasDefault(): void
    {
        $parser = new ExpressionDqlParser();
        self::assertSame('filter_entity', $parser->getRootAlias());
    }

    public function testGetWhereEmptyBeforeCompile(): void
    {
        $parser = new ExpressionDqlParser();
        self::assertSame('', $parser->getWhere());
    }

    public function testGetJoinsEmptyBeforeCompile(): void
    {
        $parser = new ExpressionDqlParser();
        self::assertSame([], $parser->getJoins());
    }

    public function testResetClearsCompiledState(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getId() == 5');
        $parser->compile();

        self::assertNotSame('', $parser->getWhere());
        self::assertNotEmpty($parser->getJoins());
        self::assertCount(1, $parser->getParameters());

        $parser->reset();

        self::assertSame('', $parser->getWhere());
        self::assertSame([], $parser->getJoins());
        self::assertCount(0, $parser->getParameters());
    }

    public function testDynamicAttributeValueFallsBackToParameter(): void
    {
        $other = new ExpressionDqlParserValueObject(88);
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '', 'other' => $other]);
        $parser->setExpression('entity.getId() == other.getId()');
        $parser->compile();

        self::assertSame(['filter_parameter_1' => 88], $parser->getParametersArray());
        self::assertStringContainsString(':filter_parameter_1', $parser->getWhere());
    }

    public function testInvalidDynamicAttributeThrowsValidatorException(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '', 'other' => new \stdClass()]);
        $parser->setExpression('entity.getId() == other.getMissing()');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Failed to evaluate dynamic value');

        $parser->compile();
    }

    public function testGetSourceRequiresDataClass(): void
    {
        $parser = new ExpressionDqlParser();

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Data class is not set');

        $parser->getSource();
    }

    public function testGetSourceIncludesJoinsAndWhere(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getParent().getId() == 1');
        $parser->compile();

        $source = $parser->getSource();

        self::assertStringContainsString('SELECT filter_entity FROM App\Common\Entity\Content filter_entity', $source);
        self::assertStringContainsString('LEFT JOIN filter_entity.category filter_entity_category', $source);
        self::assertStringContainsString('WHERE', $source);
    }

    public function testValidateFragmentsRequiresDataClass(): void
    {
        $parser = new ExpressionDqlParser();

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Parser dataClass is not set');

        $parser->validateFragments($this->createMock(EntityManagerInterface::class));
    }

    public function testValidateFragmentsAcceptsAssociationsAndFields(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('RootEntity');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getId() == 1');
        $parser->compile();

        $em = $this->createMetadataEntityManager([
            'RootEntity' => [
                'associations' => ['category' => 'CategoryEntity'],
                'fields' => [],
            ],
            'CategoryEntity' => [
                'associations' => [],
                'fields' => ['id'],
            ],
        ]);

        $parser->validateFragments($em);
        self::assertTrue(true);
    }

    public function testValidateFragmentsRejectsUnknownWhereProperty(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('RootEntity');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getMissing() == 1');
        $parser->compile();

        $em = $this->createMetadataEntityManager([
            'RootEntity' => [
                'associations' => [],
                'fields' => ['id'],
            ],
        ]);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Validation failed for token');

        $parser->validateFragments($em);
    }

    public function testValidateFragmentsRejectsFieldTraversal(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('RootEntity');

        $reflection = new \ReflectionClass($parser);
        $joinsProperty = $reflection->getProperty('joins');
        $joinsProperty->setValue($parser, ['filter_entity_title_suffix' => 'filter_entity.title.suffix']);

        $em = $this->createMetadataEntityManager([
            'RootEntity' => [
                'associations' => [],
                'fields' => ['title'],
            ],
        ]);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('is a field, cannot traverse');

        $parser->validateFragments($em);
    }

    private function createMetadataEntityManager(array $mapping): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturnCallback(
            function (string $class) use ($mapping): ClassMetadata {
                $definition = $mapping[$class] ?? ['associations' => [], 'fields' => []];
                $metadata = $this->createMock(ClassMetadata::class);
                $metadata->method('hasAssociation')->willReturnCallback(
                    static fn (string $name): bool => array_key_exists($name, $definition['associations'])
                );
                $metadata->method('getAssociationMapping')->willReturnCallback(
                    static fn (string $name): ManyToOneAssociationMapping => ManyToOneAssociationMapping::fromMappingArray([
                        'fieldName' => $name,
                        'sourceEntity' => $class,
                        'targetEntity' => $definition['associations'][$name],
                        'isOwningSide' => true,
                    ])
                );
                $metadata->method('hasField')->willReturnCallback(
                    static fn (string $name): bool => in_array($name, $definition['fields'], true)
                );

                return $metadata;
            }
        );

        return $em;
    }
}

final class ExpressionDqlParserValueObject
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}
