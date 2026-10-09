<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidatorException;

final class ExpressionDqlParserMatchesTest extends TestCase
{
    #[DataProvider('plainMatchProvider')]
    public function testPlainMatchUsesEscapedContainsPattern(string $pattern, string $expected): void
    {
        $parser = $this->compileMatch($pattern);

        self::assertSame("(filter_entity.title LIKE :filter_parameter_1 ESCAPE '!')", $parser->getWhere());
        self::assertSame(['filter_parameter_1' => $expected], $parser->getParametersArray());
        self::assertStringNotContainsString('REGEXP', $parser->getWhere());
    }

    /** @return iterable<string, array{string, string}> */
    public static function plainMatchProvider(): iterable
    {
        yield 'simple text' => ['abc', '%abc%'];
        yield 'spaces' => ['hello world', '%hello world%'];
        yield 'unicode' => ['库存', '%库存%'];
        yield 'percent wildcard escaped' => ['50%', '%50!%%'];
        yield 'underscore wildcard escaped' => ['a_b', '%a!_b%'];
        yield 'escape character doubled' => ['save!', '%save!!%'];
        yield 'all LIKE special characters' => ['50%_off!', '%50!%!_off!!%'];
        yield 'ordinary slash retained' => ['path/to/file', '%path/to/file%'];
        yield 'unclosed regex delimiter is plain text' => ['/abc', '%/abc%'];
        yield 'suffix without opening delimiter is plain text' => ['abc/i', '%abc/i%'];
        yield 'empty text matches every non-null string' => ['', '%%'];
    }

    #[DataProvider('regexMatchProvider')]
    public function testRegexLiteralIsNormalized(string $literal, string $expected): void
    {
        $parser = $this->compileMatch($literal);

        self::assertSame('(REGEXP(filter_entity.title, :filter_parameter_1) = TRUE)', $parser->getWhere());
        self::assertSame(['filter_parameter_1' => $expected], $parser->getParametersArray());
        self::assertStringNotContainsString(' LIKE ', $parser->getWhere());
    }

    /** @return iterable<string, array{string, string}> */
    public static function regexMatchProvider(): iterable
    {
        yield 'no flags' => ['/abc.*/', 'abc.*'];
        yield 'case insensitive and global' => ['/abc.*/ig', '(?i)abc.*'];
        yield 'global flag is ignored' => ['/^abc$/g', '^abc$'];
        yield 'unicode flag is ignored' => ['/[a-z]+/u', '[a-z]+'];
        yield 'multiline' => ['/^abc$/m', '(?m)^abc$'];
        yield 'dot all' => ['/a.b/s', '(?s)a.b'];
        yield 'extended mode' => ['/a b/x', '(?x)a b'];
        yield 'flags use stable order' => ['/abc/xsmi', '(?imsx)abc'];
        yield 'duplicate flags are collapsed' => ['/abc/iiim', '(?im)abc'];
        yield 'escaped delimiter is unescaped' => ['/a\\/b/i', '(?i)a/b'];
        yield 'empty regular expression' => ['//', ''];
        yield 'delimiter inside character class is escaped' => ['/[a\\/b]+/', '[a/b]+'];
    }

    #[DataProvider('invalidRegexFlagProvider')]
    public function testUnsupportedRegexFlagsAreRejected(string $literal): void
    {
        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Unsupported matches regex flags:');

        $this->compileMatch($literal);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRegexFlagProvider(): iterable
    {
        yield 'sticky flag' => ['/abc/y'];
        yield 'unknown lower-case flag' => ['/abc/q'];
        yield 'upper-case flag' => ['/abc/I'];
        yield 'mixed valid and invalid flags' => ['/abc/imz'];
    }

    #[DataProvider('nonStringOperandProvider')]
    public function testNonStringRightOperandIsRejected(string $operand): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getTitle() matches ' . $operand);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('The matches operator requires a string pattern.');
        $parser->compile();
    }

    /** @return iterable<string, array{string}> */
    public static function nonStringOperandProvider(): iterable
    {
        yield 'integer' => ['123'];
        yield 'float' => ['1.5'];
        yield 'boolean' => ['true'];
        yield 'null' => ['null'];
    }

    public function testFieldToFieldMatchIsRejected(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getTitle() matches entity.getBody()');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('The matches operator requires a string pattern.');
        $parser->compile();
    }

    public function testPlainAndRegexMatchesComposeWithStableParameters(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getTitle() matches "paper" && entity.getBody() matches "/draft.*/ig"');
        $parser->compile();

        self::assertSame(
            "((filter_entity.title LIKE :filter_parameter_1 ESCAPE '!') AND (REGEXP(filter_entity.body, :filter_parameter_2) = TRUE))",
            $parser->getWhere(),
        );
        self::assertSame([
            'filter_parameter_1' => '%paper%',
            'filter_parameter_2' => '(?i)draft.*',
        ], $parser->getParametersArray());
    }

    public function testMatchOnJoinedFieldRetainsJoin(): void
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getCategory().getName() matches "news"');
        $parser->compile();

        self::assertSame(['filter_entity_category' => 'filter_entity.category'], $parser->getJoins());
        self::assertSame("(filter_entity_category.name LIKE :filter_parameter_1 ESCAPE '!')", $parser->getWhere());
        self::assertSame(['filter_parameter_1' => '%news%'], $parser->getParametersArray());
    }

    public function testPatternIsAlwaysParameterized(): void
    {
        $parser = $this->compileMatch("x' OR 1=1 --");

        self::assertStringNotContainsString("x' OR 1=1 --", $parser->getWhere());
        self::assertSame(['filter_parameter_1' => "%x' OR 1=1 --%"], $parser->getParametersArray());
    }

    private function compileMatch(string $pattern): ExpressionDqlParser
    {
        $parser = $this->newParser();
        $parser->setExpression('entity.getTitle() matches ' . json_encode($pattern, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $parser->compile();

        return $parser;
    }

    private function newParser(): ExpressionDqlParser
    {
        return (new ExpressionDqlParser())
            ->setDataClass('App\\Common\\Entity\\Content')
            ->setValues(['entity' => '']);
    }
}
