<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service\Dsl;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use App\Promotion\Service\Dsl\Token;
use App\Promotion\Service\Dsl\TokenType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers the remaining uncovered lines of Parser.php.
 *
 * The lexer skips blank/comment-only lines, so it can never emit two
 * consecutive EOL tokens. Several defensive branches in the parser only
 * handle *consecutive* EOL tokens; those are exercised here by feeding
 * hand-built token streams to Parser::parse() (its public entry point).
 */
final class ParserCoverageTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    private function t(TokenType $type, string $value = '', int $line = 1, int $col = 1): Token
    {
        return new Token($type, $value, $line, $col);
    }

    private function parse(array $tokens): AstNode
    {
        return $this->parser->parse($tokens);
    }

    private function findChild(AstNode $node, string $type): ?AstNode
    {
        foreach ($node->children as $child) {
            if ($child->type === $type) {
                return $child;
            }
        }
        return null;
    }

    // ──────────────────────────── top-level EOL (lines 29-30) ────────────────────────────

    #[Group('low-value')]
    public function testParseLeadingEolAtTopLevel(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::EOL),
            $this->t(TokenType::KEYWORD_TYPE, 'type'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::IDENTIFIER, 'full_reduction'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        self::assertSame('full_reduction', $ast->data['type']);
    }

    // ──────────────────────────── EOL inside logic block (lines 152-153) ────────────────────────────

    #[Group('low-value')]
    public function testParseLogicBlockSkipsLeadingEol(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_WHEN, 'when'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::KEYWORD_AND, 'and'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'cart'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::IDENTIFIER, 'subtotal'),
            $this->t(TokenType::IDENTIFIER, '>='),
            $this->t(TokenType::NUMBER, '200'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        $when = $this->findChild($ast, 'when');
        self::assertCount(1, $when->children);
        self::assertSame('and', $when->children[0]->type);
        self::assertCount(1, $when->children[0]->children);
        self::assertSame('condition', $when->children[0]->children[0]->type);
    }

    // ──────────────────────────── EOL before NOT body (line 185) ────────────────────────────

    #[Group('low-value')]
    public function testParseNotBlockSkipsLeadingEols(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_WHEN, 'when'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::KEYWORD_NOT, 'not'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'cart'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::IDENTIFIER, 'subtotal'),
            $this->t(TokenType::IDENTIFIER, '<'),
            $this->t(TokenType::NUMBER, '50'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        $when = $this->findChild($ast, 'when');
        self::assertSame('not', $when->children[0]->type);
        self::assertCount(1, $when->children[0]->children);
        self::assertSame('<', $when->children[0]->children[0]->data['op']);
    }

    // ──────────────────────────── operand errors (lines 229-233) ────────────────────────────

    public function testParseOperandRejectsColon(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected operand');

        $this->parse([
            $this->t(TokenType::KEYWORD_WHEN, 'when'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'cart'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::IDENTIFIER, 'subtotal'),
            $this->t(TokenType::IDENTIFIER, '>='),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── operator errors (line 267) ────────────────────────────

    public function testParseOperatorRejectsString(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected operator');

        $this->parse([
            $this->t(TokenType::KEYWORD_WHEN, 'when'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'cart'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::IDENTIFIER, 'subtotal'),
            $this->t(TokenType::STRING, 'x'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── do: EOL handling (lines 293-294) ────────────────────────────

    #[Group('low-value')]
    public function testParseDoSkipsLeadingEol(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'order'),
            $this->t(TokenType::NUMBER, '10'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        $do = $this->findChild($ast, 'do');
        self::assertCount(1, $do->children);
        self::assertSame('action_discount', $do->children[0]->type);
        self::assertSame(10, $do->children[0]->data['value']);
    }

    // ──────────────────────────── discount order: max cap errors (lines 366, 389, 396) ────────────────────────────

    public function testParseDiscountOrderPercentMaxCapNonNumber(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected max cap value');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'order'),
            $this->t(TokenType::NUMBER, '10'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::IDENTIFIER, 'max'),
            $this->t(TokenType::IDENTIFIER, 'foo'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParseDiscountOrderConfigPercentMaxCapNonNumber(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected max cap value');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'order'),
            $this->t(TokenType::IDENTIFIER, 'config'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::IDENTIFIER, 'rate'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::IDENTIFIER, 'max'),
            $this->t(TokenType::IDENTIFIER, 'foo'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParseDiscountOrderRejectsNonConfigIdentifier(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected number for discount amount');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'order'),
            $this->t(TokenType::IDENTIFIER, 'abc'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── discount item: errors (lines 411, 418) ────────────────────────────

    public function testParseDiscountItemRejectsNonNumberPosition(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected item position number');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'item'),
            $this->t(TokenType::IDENTIFIER, 'x'),
            $this->t(TokenType::NUMBER, '50'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParseDiscountItemRejectsNonNumberRate(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected discount rate');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'item'),
            $this->t(TokenType::NUMBER, '2'),
            $this->t(TokenType::IDENTIFIER, 'abc'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── discount items: numeric rate / config errors (lines 437, 444, 449) ────────────────────────────

    public function testParseDiscountItemsNumericRate(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'items'),
            $this->t(TokenType::NUMBER, '30'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        $do = $this->findChild($ast, 'do');
        $action = $do->children[0];
        self::assertSame('items', $action->data['target']);
        self::assertSame(30, $action->data['rate']);
        self::assertTrue($action->data['isPercent']);
    }

    public function testParseDiscountItemsRejectsNonIdentifierConfigProperty(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected config property');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'items'),
            $this->t(TokenType::IDENTIFIER, 'config'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::NUMBER, '5'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParseDiscountItemsRejectsPlainIdentifierRate(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected discount rate');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'items'),
            $this->t(TokenType::IDENTIFIER, 'foo'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── add gift / member discount errors (lines 464, 497, 502) ────────────────────────────

    public function testParseAddRejectsNonGiftVerb(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Expected 'gift'");

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'add'),
            $this->t(TokenType::IDENTIFIER, 'nope'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParseMemberRejectsNonDiscountVerb(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Expected 'discount'");

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'member'),
            $this->t(TokenType::IDENTIFIER, 'foo'),
            $this->t(TokenType::NUMBER, '90'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParseMemberRejectsNonNumberRate(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected discount rate');

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'member'),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::IDENTIFIER, 'foo'),
            $this->t(TokenType::PERCENT, '%'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── tiered: EOL / break / string value (lines 521-522, 528, 552) ────────────────────────────

    #[Group('low-value')]
    public function testParseTieredSkipsLeadingEol(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::KEYWORD_TIERED, 'tiered'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOL),
            $this->t(TokenType::DASH, '-'),
            $this->t(TokenType::IDENTIFIER, 'threshold'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::NUMBER, '100'),
            $this->t(TokenType::IDENTIFIER, 'discount'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::NUMBER, '10'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        $do = $this->findChild($ast, 'do');
        self::assertSame('action_tiered', $do->children[0]->type);
        self::assertCount(1, $do->children[0]->children);
        self::assertSame(100, $do->children[0]->children[0]->data['threshold']);
    }

    public function testParseTieredStopsAtNonDashToken(): void
    {
        // A stray non-dash, non-EOL token terminates the tiered block; the
        // leftover token is then rejected by the enclosing do: block.
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Unknown action 'foo'");

        $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::KEYWORD_TIERED, 'tiered'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::DASH, '-'),
            $this->t(TokenType::IDENTIFIER, 'threshold'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::NUMBER, '100'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'foo'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParseTierEntryAcceptsStringValue(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_DO, 'do'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::KEYWORD_TIERED, 'tiered'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::DASH, '-'),
            $this->t(TokenType::IDENTIFIER, 'threshold'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::IDENTIFIER, 'high'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        $do = $this->findChild($ast, 'do');
        self::assertSame('tier', $do->children[0]->children[0]->type);
        self::assertSame('high', $do->children[0]->children[0]->data['threshold']);
    }

    // ──────────────────────────── priority (lines 570, 575-580, 651) ────────────────────────────

    public function testParsePriorityEmptyValueThrowsAndReachesEndOfStream(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected priority value');

        $this->parse([
            $this->t(TokenType::KEYWORD_PRIORITY, 'priority'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    public function testParsePriorityConfigReference(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_PRIORITY, 'priority'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::IDENTIFIER, 'config'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::IDENTIFIER, 'my_level'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        self::assertSame('config.my_level', $ast->data['priority']['value']);
    }

    public function testParsePriorityConfigPropertyNonIdentifierThrows(): void
    {
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage('Expected config property');

        $this->parse([
            $this->t(TokenType::KEYWORD_PRIORITY, 'priority'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::IDENTIFIER, 'config'),
            $this->t(TokenType::DOT, '.'),
            $this->t(TokenType::NUMBER, '5'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── fields: EOL / break (lines 607-608, 614) ────────────────────────────

    #[Group('low-value')]
    public function testParseFieldsSkipsLeadingEol(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_FIELDS, 'fields'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'a'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::IDENTIFIER, 'number'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::STRING, 'A'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        $fields = $this->findChild($ast, 'fields');
        self::assertCount(1, $fields->children);
        self::assertSame('field', $fields->children[0]->type);
        self::assertSame('a', $fields->children[0]->data['name']);
    }

    public function testParseFieldsStopsAtNonIdentifierToken(): void
    {
        // A stray dash after a field declaration terminates the fields block
        // and is then rejected at the top level.
        $this->expectException(DslSyntaxException::class);
        $this->expectExceptionMessage("Unexpected keyword '-'");

        $this->parse([
            $this->t(TokenType::KEYWORD_FIELDS, 'fields'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::IDENTIFIER, 'a'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::IDENTIFIER, 'number'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::STRING, 'A'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::DASH, '-'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);
    }

    // ──────────────────────────── skipToEol advance (line 677) ────────────────────────────

    public function testParseTypeSkipToEolConsumesTrailingTokens(): void
    {
        $ast = $this->parse([
            $this->t(TokenType::KEYWORD_TYPE, 'type'),
            $this->t(TokenType::COLON, ':'),
            $this->t(TokenType::IDENTIFIER, 'full_reduction'),
            $this->t(TokenType::IDENTIFIER, 'extra'),
            $this->t(TokenType::IDENTIFIER, 'junk'),
            $this->t(TokenType::EOL),
            $this->t(TokenType::EOF, '', 99, 1),
        ]);

        self::assertSame('full_reduction', $ast->data['type']);
    }

    // ──────────────────────────── lexer round-trip sanity ────────────────────────────

    #[Group('low-value')]
    public function testLexerRoundTripItemsNumericRate(): void
    {
        $lexer = new Lexer();
        $ast = $this->parser->parse($lexer->tokenize("type: discount\ndo:\n  discount items 25%"));

        $do = $this->findChild($ast, 'do');
        self::assertSame(25, $do->children[0]->data['rate']);
        self::assertTrue($do->children[0]->data['isPercent']);
    }
}
