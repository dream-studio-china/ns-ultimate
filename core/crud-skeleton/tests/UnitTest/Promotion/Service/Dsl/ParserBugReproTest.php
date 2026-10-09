<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service\Dsl;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use App\Promotion\Service\Dsl\TokenType;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

/**
 * Regression guards for known defects in the Promotion DSL. Each test pins the
 * CURRENT (possibly incorrect) behaviour; tests that express the intended
 * behaviour are marked skipped because the source cannot yet satisfy them.
 *
 * Do not fix by editing this file alone — the fixes belong in src/, which is
 * out of scope for this coverage task.
 */
final class ParserBugReproTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
    }

    private function parse(string $dsl): AstNode
    {
        return $this->parser->parse($this->lexer->tokenize($dsl));
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

    // ─────────────────────────────────────────────────────────────────────
    // Bug A: "type: tiered" is unparseable because 'tiered' is lexed as a
    // keyword (TokenType::KEYWORD_TIERED) while parseType() demands an
    // IDENTIFIER. PromotionTemplate::TYPE_TIERED ('tiered') therefore cannot
    // be expressed as a DSL header, so a tiered template can never round-trip
    // through parseDsl()/update().
    // ─────────────────────────────────────────────────────────────────────

    public function testTieredTypeHeaderIsRejectedByParser(): void
    {
        $dsl = "type: tiered\ndo:\n  tiered:\n    - threshold: 100 discount: 10";

        try {
            $this->parse($dsl);
            $threw = false;
        } catch (DslSyntaxException $e) {
            $threw = true;
            self::assertStringContainsString('Expected promotion type identifier', $e->getMessage());
        }

        self::assertTrue($threw, 'type: tiered unexpectedly parsed without error');
    }

    public function testTieredTypeRoundTripFails(): void
    {
        // Templates of type 'tiered' cannot be authored via their DSL header.
        $this->expectException(DslSyntaxException::class);

        $this->parse("type: tiered\ndo:\n  tiered:\n    - threshold: 100 discount: 10");
    }

    public function testTieredTypeHeaderShouldParseSuccessfully(): void
    {
        // Intended behaviour: PromotionTemplate::TYPE_TIERED ('tiered') should
        // be expressible as the type header. Fails on the current source.
        $this->markTestSkipped('Bug A: lexer emits KEYWORD_TIERED, parseType() expects an IDENTIFIER');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bug B: a top-level condition written after an and:/or: logic block is
    // silently absorbed into that block, so "(a OR b) AND c" parses as
    // "a OR b OR c". The grammar cannot express mixed logic, leading to
    // false-positive matches.
    // ─────────────────────────────────────────────────────────────────────

    public function testOrBlockAbsorbsSiblingCondition(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  or:\n    cart.subtotal >= 1000\n    cart.subtotal <= 10\n  cart.subtotal == 500";
        $ast = $this->parse($dsl);

        $when = $this->findChild($ast, 'when');
        self::assertNotNull($when);
        // Only one top-level child — the trailing condition was absorbed.
        self::assertCount(1, $when->children);
        self::assertSame('or', $when->children[0]->type);
        // ...and the or-block now holds three children instead of two.
        self::assertCount(3, $when->children[0]->children);
    }

    public function testOrBlockAbsorbsSiblingConditionSemantics(): void
    {
        // cart.subtotal = 500 must NOT satisfy "(>= 1000 OR <= 10) AND == 500",
        // but the absorbed form "OR(>=1000, <=10, ==500)" matches.
        $dsl = "type: full_reduction\nwhen:\n  or:\n    cart.subtotal >= 1000\n    cart.subtotal <= 10\n  cart.subtotal == 500";
        $ast = $this->parse($dsl);

        $when = $this->findChild($ast, 'when');
        $evaluator = new Evaluator();
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 500;

        // Current behaviour: matched (buggy — the ==500 disjunct leaks into the OR).
        self::assertTrue($evaluator->evaluateCondition($when->children[0], $context, []));
    }

    public function testMixedLogicParsesAsSiblingBlocks(): void
    {
        // Intended behaviour: "(cart >= 1000 OR cart <= 10) AND cart == 500"
        // should produce two when children (or + condition) and evaluate to
        // false when cart.subtotal = 500. Fails on the current source.
        $this->markTestSkipped('Bug B: logic blocks absorb trailing sibling conditions');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bug C: a path with a trailing dot ("cart. >= 200") is rejected, but with
    // a misleading "Expected operator" error rather than a path syntax error,
    // because the '.' swallows the following operator token into the path.
    // ─────────────────────────────────────────────────────────────────────

    public function testTrailingDotPathRejectedWithConfusingMessage(): void
    {
        try {
            $this->parse("type: full_reduction\nwhen:\n  cart. >= 200");
            $threw = false;
        } catch (DslSyntaxException $e) {
            $threw = true;
            // The real problem is the malformed path, not a missing operator.
            self::assertStringContainsString('Expected operator', $e->getMessage());
        }

        self::assertTrue($threw, 'malformed trailing-dot path unexpectedly parsed');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bug D (minor): the lexer never produces consecutive EOL tokens because it
    // drops blank/comment-only lines, so the parser's "skip stray EOL" branches
    // (e.g. Parser::parseLogicBlock lines 152-153) are dead code for any input
    // produced by the lexer. This is latent fragility rather than a defect.
    // ─────────────────────────────────────────────────────────────────────

    public function testLexerNeverEmitsConsecutiveEolTokens(): void
    {
        $tokens = $this->lexer->tokenize("type: full_reduction\n\n\npriority: 100\n");

        $eolRuns = 0;
        $consecutive = 0;
        $previousWasEol = false;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::EOL) {
                if ($previousWasEol) {
                    $consecutive++;
                }
                $eolRuns++;
                $previousWasEol = true;
            } else {
                $previousWasEol = false;
            }
        }

        self::assertGreaterThan(0, $eolRuns);
        self::assertSame(0, $consecutive, 'blank lines should be skipped by the lexer');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bug F: the DSL grammar permits multiple when: sections, but the service
    // layer is inconsistent about which one it honours:
    //   PromotionService::findWhenNode()  -> first when node
    //   PromotionTemplateService::simulate() -> last when node
    // A second when: block is silently ignored during real evaluation.
    // ─────────────────────────────────────────────────────────────────────

    public function testParserAllowsMultipleWhenSections(): void
    {
        $dsl = "type: full_reduction\nwhen:\n  cart.subtotal >= 1000\nwhen:\n  cart.subtotal >= 100";
        $ast = $this->parse($dsl);

        $whenCount = 0;
        foreach ($ast->children as $child) {
            if ($child->type === 'when') {
                $whenCount++;
            }
        }
        // Two when sections survive the parse...
        self::assertSame(2, $whenCount);
        // ...but PromotionService::findWhenNode() only ever returns the first,
        // and simulate() only reports the last — see report for details.
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bug G: the "desc" modifier parsed from "priority: X desc" is stored in
    // the AST but never read anywhere in src/Promotion. PromotionService::
    // getPriority() only consults ['value'], and getAvailable() always sorts
    // descending, so the flag is a no-op.
    // ─────────────────────────────────────────────────────────────────────

    public function testPriorityDescFlagIsParsedButUnused(): void
    {
        $ast = $this->parse("type: full_reduction\npriority: 100 desc");

        self::assertSame('100', $ast->data['priority']['value']);
        self::assertTrue($ast->data['priority']['desc']);

        // Grep for 'desc' outside the Dsl namespace shows no consumer in
        // src/Promotion — the flag never influences ordering or priority.
        self::assertArrayHasKey('desc', $ast->data['priority']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bug E (documentation only): PromotionTemplateService::simulate() reports
    // matched=false when the DSL has no when: section, whereas the real
    // pipeline (PromotionService::evaluateDslConditions) treats a missing
    // when as "always match". Simulate under-reports matches for
    // always-on promotions.
    // ─────────────────────────────────────────────────────────────────────

    public function testSimulateNoWhenNodeAlwaysMatches(): void
    {
        // Intended behaviour: a template with a do: block but no when: block
        // should simulate as matched. Requires PromotionTemplateService, which
        // is covered by PromotionTemplateServiceTest; kept here as documentation.
        $this->markTestSkipped('Bug E: simulate() returns matched=false for templates without a when: node');
    }
}
