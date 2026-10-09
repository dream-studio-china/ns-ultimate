<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service\Dsl;

use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\TokenType;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    public function testTokenizeEmptyInput(): void
    {
        $tokens = $this->lexer->tokenize('');

        self::assertCount(1, $tokens);
        self::assertSame(TokenType::EOF, $tokens[0]->type);
    }

    public function testTokenizeCommentOnlyLines(): void
    {
        $tokens = $this->lexer->tokenize("# this is a comment\n# another comment\n");

        self::assertCount(1, $tokens);
        self::assertSame(TokenType::EOF, $tokens[0]->type);
    }

    public function testTokenizeBlankLines(): void
    {
        $tokens = $this->lexer->tokenize("\n\n\n");

        self::assertCount(1, $tokens);
        self::assertSame(TokenType::EOF, $tokens[0]->type);
    }

    public function testTokenizeTypeKeyword(): void
    {
        $tokens = $this->lexer->tokenize('type: full_reduction');

        self::assertSame(TokenType::KEYWORD_TYPE, $tokens[0]->type);
        self::assertSame('type', $tokens[0]->value);
        self::assertSame(TokenType::COLON, $tokens[1]->type);
        self::assertSame(':', $tokens[1]->value);
        self::assertSame(TokenType::IDENTIFIER, $tokens[2]->type);
        self::assertSame('full_reduction', $tokens[2]->value);
        self::assertSame(TokenType::EOL, $tokens[3]->type);
        self::assertSame(TokenType::EOF, $tokens[4]->type);
    }

    public function testTokenizeAllKeywords(): void
    {
        $keywords = ['type', 'phase', 'when', 'do', 'priority', 'fields', 'and', 'or', 'not', 'tiered'];
        $expectedTypes = [
            TokenType::KEYWORD_TYPE,
            TokenType::KEYWORD_PHASE,
            TokenType::KEYWORD_WHEN,
            TokenType::KEYWORD_DO,
            TokenType::KEYWORD_PRIORITY,
            TokenType::KEYWORD_FIELDS,
            TokenType::KEYWORD_AND,
            TokenType::KEYWORD_OR,
            TokenType::KEYWORD_NOT,
            TokenType::KEYWORD_TIERED,
        ];

        foreach ($keywords as $i => $kw) {
            $tokens = $this->lexer->tokenize($kw);
            self::assertSame($expectedTypes[$i], $tokens[0]->type, "Keyword '$kw' should have token type {$expectedTypes[$i]->value}");
            self::assertSame($kw, $tokens[0]->value);
        }
    }

    public function testTokenizeNumber(): void
    {
        $tokens = $this->lexer->tokenize('200');

        self::assertSame(TokenType::NUMBER, $tokens[0]->type);
        self::assertSame('200', $tokens[0]->value);
    }

    public function testTokenizeDecimalNumber(): void
    {
        $tokens = $this->lexer->tokenize('19.99');

        self::assertSame(TokenType::NUMBER, $tokens[0]->type);
        self::assertSame('19.99', $tokens[0]->value);
    }

    public function testTokenizeQuotedString(): void
    {
        $tokens = $this->lexer->tokenize('"hello world"');

        self::assertSame(TokenType::STRING, $tokens[0]->type);
        self::assertSame('hello world', $tokens[0]->value);
    }

    public function testTokenizeEmptyString(): void
    {
        $tokens = $this->lexer->tokenize('""');

        self::assertSame(TokenType::STRING, $tokens[0]->type);
        self::assertSame('', $tokens[0]->value);
    }

    public function testUnterminatedStringThrowsException(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Unterminated string');

        $this->lexer->tokenize('"unterminated');
    }

    public function testTokenizeOperators(): void
    {
        $ops = ['>=', '<=', '==', '>', '<'];
        foreach ($ops as $op) {
            $tokens = $this->lexer->tokenize($op);
            self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type, "Operator '$op' should be IDENTIFIER");
            self::assertSame($op, $tokens[0]->value);
        }
    }

    public function testTokenizeNotEqualIsNotValid(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Unexpected character '!'");

        $this->lexer->tokenize('!=');
    }

    public function testTokenizeSingleEqualThrowsException(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Unexpected character '='");

        $this->lexer->tokenize('=');
    }

    public function testTokenizeMidLineComment(): void
    {
        $tokens = $this->lexer->tokenize('type: full_reduction # this is a comment');

        self::assertSame(TokenType::KEYWORD_TYPE, $tokens[0]->type);
        self::assertSame(TokenType::COLON, $tokens[1]->type);
        self::assertSame(TokenType::IDENTIFIER, $tokens[2]->type);
        self::assertSame('full_reduction', $tokens[2]->value);
        self::assertSame(TokenType::EOL, $tokens[3]->type);
        self::assertSame(TokenType::EOF, $tokens[4]->type);
    }

    public function testTokenizeInvalidCharacterThrowsException(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Unexpected character '!'");

        $this->lexer->tokenize('!');
    }

    public function testTokenizeMultiByteIdentifier(): void
    {
        $tokens = $this->lexer->tokenize('中文标识符');

        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('中文标识符', $tokens[0]->value);
    }

    public function testTokenizeMultiByteMixed(): void
    {
        $tokens = $this->lexer->tokenize('type: 满减优惠');

        self::assertSame(TokenType::KEYWORD_TYPE, $tokens[0]->type);
        self::assertSame(TokenType::COLON, $tokens[1]->type);
        self::assertSame(TokenType::IDENTIFIER, $tokens[2]->type);
        self::assertSame('满减优惠', $tokens[2]->value);
    }

    public function testTokenizeSymbols(): void
    {
        $tokens = $this->lexer->tokenize(': - . %');

        self::assertSame(TokenType::COLON, $tokens[0]->type);
        self::assertSame(':', $tokens[0]->value);
        self::assertSame(TokenType::DASH, $tokens[1]->type);
        self::assertSame('-', $tokens[1]->value);
        self::assertSame(TokenType::DOT, $tokens[2]->type);
        self::assertSame('.', $tokens[2]->value);
        self::assertSame(TokenType::PERCENT, $tokens[3]->type);
        self::assertSame('%', $tokens[3]->value);
    }

    public function testTokenizeConfigPath(): void
    {
        $tokens = $this->lexer->tokenize('config.threshold');

        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('config', $tokens[0]->value);
        self::assertSame(TokenType::DOT, $tokens[1]->type);
        self::assertSame(TokenType::IDENTIFIER, $tokens[2]->type);
        self::assertSame('threshold', $tokens[2]->value);
    }

    public function testTokenizeCartPath(): void
    {
        $tokens = $this->lexer->tokenize('cart.subtotal');

        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('cart', $tokens[0]->value);
        self::assertSame(TokenType::DOT, $tokens[1]->type);
        self::assertSame(TokenType::IDENTIFIER, $tokens[2]->type);
        self::assertSame('subtotal', $tokens[2]->value);
    }

    public function testTokenizeDeepDottedPath(): void
    {
        $tokens = $this->lexer->tokenize('cart.items.count');

        // cart . items . count EOL EOF = 7 tokens
        self::assertCount(7, $tokens);
        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('cart', $tokens[0]->value);
        self::assertSame(TokenType::DOT, $tokens[1]->type);
        self::assertSame(TokenType::IDENTIFIER, $tokens[2]->type);
        self::assertSame('items', $tokens[2]->value);
        self::assertSame(TokenType::DOT, $tokens[3]->type);
        self::assertSame(TokenType::IDENTIFIER, $tokens[4]->type);
        self::assertSame('count', $tokens[4]->value);
        self::assertSame(TokenType::EOL, $tokens[5]->type);
        self::assertSame(TokenType::EOF, $tokens[6]->type);
    }

    public function testTokenizeLineNumbers(): void
    {
        $tokens = $this->lexer->tokenize("type: full_reduction\nphase: inner");

        self::assertSame(1, $tokens[0]->line);
        self::assertSame(1, $tokens[1]->line);
        self::assertSame(1, $tokens[2]->line);
        self::assertSame(2, $tokens[4]->line);
        self::assertSame(2, $tokens[5]->line);
        self::assertSame(2, $tokens[6]->line);
    }

    public function testTokenizeColumnNumbers(): void
    {
        $tokens = $this->lexer->tokenize('  type');

        self::assertSame(3, $tokens[0]->col);
    }

    public function testTokenizeIdentifierWithParentheses(): void
    {
        $tokens = $this->lexer->tokenize('field_label(Custom)');

        self::assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        self::assertSame('field_label(Custom)', $tokens[0]->value);
    }

    public function testTokenizeLeadingDotAsNumber(): void
    {
        // DOT handler fires before NUMBER, so .5 -> DOT(.) + NUMBER(5)
        $tokens = $this->lexer->tokenize('.5');

        self::assertSame(TokenType::DOT, $tokens[0]->type);
        self::assertSame(TokenType::NUMBER, $tokens[1]->type);
        self::assertSame('5', $tokens[1]->value);
    }

    public function testTokenizeFullDslAllSevenTypes(): void
    {
        // 'tiered' is a keyword (KEYWORD_TIERED), not an identifier.
        // The remaining 6 are valid IDENTIFIER types.
        $dslTypes = [
            'full_reduction' => TokenType::IDENTIFIER,
            'discount' => TokenType::IDENTIFIER,
            'gift' => TokenType::IDENTIFIER,
            'nth_discount' => TokenType::IDENTIFIER,
            'free_shipping' => TokenType::IDENTIFIER,
            'member_discount' => TokenType::IDENTIFIER,
            'tiered' => TokenType::KEYWORD_TIERED,
        ];

        foreach ($dslTypes as $type => $expectedTokenType) {
            $tokens = $this->lexer->tokenize("type: {$type}");
            self::assertSame(TokenType::KEYWORD_TYPE, $tokens[0]->type);
            self::assertSame($expectedTokenType, $tokens[2]->type);
            self::assertSame($type, $tokens[2]->value);
        }
    }
}
