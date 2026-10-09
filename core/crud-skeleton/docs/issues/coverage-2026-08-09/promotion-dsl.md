# Promotion DSL + services — coverage to ~100% and bug report

Date: 2026-08-09
Scope: `src/Promotion/Service/Dsl/Parser.php`, `src/Promotion/Service/Dsl/Evaluator.php`, `src/Promotion/Service/PromotionCalculator.php`, `src/Promotion/Service/PromotionService.php`, `src/Promotion/Service/PromotionTemplateService.php`
Runner: `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit <file> --no-coverage`
Rule: **no changes under `src/`** — only tests added under `tests/` plus this report.

## Coverage before → after

Verified with Xdebug coverage against the full `tests/Promotion` suite (360 tests):

| File | Before | After |
|---|---|---|
| `Dsl/Parser.php` | 89.46% (330/370) | **100%** (370/370 lines, 37/37 methods) |
| `Dsl/Evaluator.php` | 96.47% (82/85) | **100%** (85/85, 12/12) |
| `PromotionCalculator.php` | 97.83% (90/92) | **100%** (92/92, 5/5) |
| `PromotionService.php` | 98.89% (89/90) | **100%** (90/90, 10/10) |
| `PromotionTemplateService.php` | 97.4% (75/77) | **100%** (77/77, 5/5) |

## Test files added (6 files, 54 tests: 51 passing + 3 skipped)

- `tests/Promotion/Service/Dsl/ParserCoverageTest.php` (27 tests) — every remaining grammar production and error branch:
  - top-level EOL (29–30); EOL-skip in logic/not/do/tiered/fields blocks (152–153, 185, 293–294, 521–522, 607–608) via hand-built token streams (the lexer never emits consecutive EOLs, see Bug 5);
  - operand/operator errors (229–233, 267); max-cap non-number (366, 389); non-config identifier (396); item position/rate errors (411, 418); items numeric rate + config errors (437, 444, 449); add/member errors (464, 497, 502); tiered break/string values (528, 552); priority empty/config refs/errors (570, 575–580, 651); fields break (614); `skipToEol` (677).
- `tests/Promotion/Service/Dsl/EvaluatorCoverageTest.php` (7 tests) — null operands (124), `user.level` with/without profile (168), `user.tags` (171), plus `user.id` (165).
- `tests/Promotion/Service/Dsl/ParserBugReproTest.php` (11 tests: 8 passing guards + 3 skipped) — regression guards for the bugs below.
- `tests/Promotion/Service/PromotionCalculatorCoverageTest.php` (3 tests) — exclusive conflict-mode break (64), best-price scan skipping non-best-price candidates (143), best-price skip during the standard scan (123).
- `tests/Promotion/Service/PromotionServiceCoverageTest.php` (2 tests) — `getPriority()` fallback for a non-numeric, non-config value (225).
- `tests/Promotion/Service/PromotionTemplateServiceCoverageTest.php` (4 tests) — `update()` type mismatch (125) and phase mismatch (128) + acceptance paths.

No new deprecations/notices/warnings are introduced. The 5 deprecations and 19 mock-object notices reported by the full `tests/Promotion` run are pre-existing (`tests/Promotion/Controller/Manage/PromotionControllerTest.php:79` uses the deprecated `ReflectionProperty::setAccessible()`, and older tests create mocks without expectations). Suite stays green.

## Bugs found (reported only — no source changed)

### Bug 1 (MEDIUM) — `type: tiered` can never be parsed

- **File / line:** `src/Promotion/Service/Dsl/Lexer.php:20` (keyword table) interacting with `src/Promotion/Service/Dsl/Parser.php:66-67` (`parseType()` requires an `IDENTIFIER`).
- **Description:** `'tiered'` is lexed as `TokenType::KEYWORD_TIERED`, but `parseType()` only accepts an `IDENTIFIER` for the type header. `PromotionTemplate::TYPE_TIERED = 'tiered'` (src/Promotion/Entity/PromotionTemplate.php:24) is therefore the **only** promotion type that cannot be written in DSL.
- **Impact:** a tiered promotion template can never round-trip through `PromotionTemplateService::parseDsl()`/`update()` with its own type header (`type: tiered` throws `Expected promotion type identifier`). Type `'tiered'` is silently unusable even though a `TieredStrategy` exists.
- **Reproduction:** `(new Parser())->parse((new Lexer())->tokenize("type: tiered\ndo:\n  tiered:\n    - threshold: 100 discount: 10"))` throws at `[1:7] Expected promotion type identifier`.
- **Proposed fix:** in `parseType()`, accept the `KEYWORD_TIERED` token as a valid type identifier (its value is already `'tiered'`), or remove the keyword status of `tiered` and keyword-check it only in `parseAction()`.

### Bug 2 (MEDIUM) — a sibling condition after an `and:`/`or:` block is swallowed

- **File / line:** `src/Promotion/Service/Dsl/Parser.php` — `parseLogicBlock()` loop, lines 148–171 (the block keeps consuming until a *section* keyword).
- **Description:** once a logic block is entered, every following condition line is absorbed into it, so a mixed expression `(a OR b) AND c` parses as `a OR b OR c`. The grammar cannot express a condition that follows a logic block.
- **Impact:** false-positive matches. A promotion intended to require `(cart >= 1000 OR cart <= 10) AND cart == 500` matches whenever `cart == 500` alone, because the `== 500` condition leaks into the `OR`.
- **Reproduction:** DSL `when:\n  or:\n    cart.subtotal >= 1000\n    cart.subtotal <= 10\n  cart.subtotal == 500` with `cart.subtotal = 500` evaluates **true** (intended: false). Guarded by `ParserBugReproTest::testOrBlockAbsorbsSiblingCondition*`.
- **Proposed fix:** terminate a logic block on the first non-indented (top-level) condition, or require explicit block delimiters; e.g. track indentation in tokens so `parseLogicBlock()` stops when a sibling condition starts at the parent indentation.

### Bug 3 (LOW) — misleading error for a path with a trailing dot

- **File / line:** `src/Promotion/Service/Dsl/Parser.php:254-258` (`parseDottedPath()`).
- **Description:** `cart. >= 200` consumes the `>=` operator into the path (`cart.>=`), then fails in `parseOperator()` with `Expected operator` — the message names the wrong problem.
- **Impact:** malformed DSL is rejected (no silent wrong evaluation), but diagnostics point at the operator instead of the malformed path.
- **Reproduction:** `ParserBugReproTest::testTrailingDotPathRejectedWithConfusingMessage`.
- **Proposed fix:** in `parseDottedPath()`, after consuming a `.`, validate that the following token is an `IDENTIFIER` (or that the dot is not the last token on the path) and throw `Expected property name after '.'`.

### Bug 4 (LOW) — `priority: X desc` flag is parsed but never used

- **File / line:** `src/Promotion/Service/Dsl/Parser.php:586-589` writes `data['desc']`; `src/Promotion/Service/PromotionService.php:201-226` (`getPriority()`) reads only `value`; `getAvailable()` always sorts descending (`usort($sorted, fn($a,$b) => getPriority($b) <=> getPriority($a))`, line 75-77).
- **Description:** the `desc` modifier is stored in the AST but has no consumer anywhere in `src/Promotion` (grep confirms). Because sorting is already always descending, the flag is a silent no-op — users writing `priority: 100 desc` get no direction change and no error.
- **Impact:** dead feature / misleading DSL; a future "asc" direction would also be ignored.
- **Reproduction:** `ParserBugReproTest::testPriorityDescFlagIsParsedButUnused`.
- **Proposed fix:** either honour `desc` in the comparator, or reject `desc` (or any direction word) with a clear error.

### Bug 5 (LOW) — `simulate()` disagrees with the real pipeline for templates without `when:`

- **File / line:** `src/Promotion/Service/PromotionTemplateService.php:76-100` (`$matched` stays `false` when no `when` child exists) vs `src/Promotion/Service/PromotionService.php:126-134` (`evaluateDslConditions()` returns `true` when there is no `when` node).
- **Description:** an always-on promotion (a `do:` block with no `when:`) is applied by the pricing pipeline but `simulate()` reports `matched = false`.
- **Impact:** the simulation/preview UI under-reports matches for always-on campaigns; a user may believe the campaign is not active.
- **Reproduction:** `testSimulateReturnsStructure` in the existing suite already codifies `matched=false`; intended behaviour is documented in `ParserBugReproTest::testSimulateNoWhenNodeAlwaysMatches` (skipped).
- **Proposed fix:** initialise `$matched` to `true` when the AST contains a `do:` block but no `when:` node (mirroring `evaluateDslConditions()`).

### Bug 6 (LOW) — multiple `when:` sections are handled inconsistently

- **File / line:** `src/Promotion/Service/Dsl/Parser.php:37-39` allows repeated `when:` sections; `src/Promotion/Service/PromotionService.php:150-158` (`findWhenNode()`) returns only the **first**; `src/Promotion/Service/PromotionTemplateService.php:81-92` (`simulate()`) reports only the **last**.
- **Description:** with two `when:` blocks, real evaluation enforces only the first while `simulate()` shows only the last. There is no error and no combination of both.
- **Impact:** conditions written after the first `when:` are silently ignored in production, changing what promotions actually match.
- **Reproduction:** `ParserBugReproTest::testParserAllowsMultipleWhenSections` shows two `when` nodes surviving the parse.
- **Proposed fix:** reject a second `when:` section at parse time, or make both `findWhenNode()`/`simulate()` honour all `when:` nodes (AND them together).

### Bug 7 (LOW, robustness) — parser EOL-skip branches are unreachable from lexer output

- **File / line:** `src/Promotion/Service/Dsl/Lexer.php:39-41` (blank/comment lines are skipped, so at most one `EOL` exists per physical line) vs the parser's "consume stray EOL and continue" branches (`Parser.php` 152–153, 185, 293–294, 521–522, 607–608).
- **Description:** consecutive `EOL` tokens cannot be produced by the lexer, so those defensive branches are dead for any DSL string. They are only reachable by feeding hand-built token arrays to `Parser::parse()` (as `ParserCoverageTest` does).
- **Impact:** latent fragility rather than an observable defect; if the lexer ever changes (e.g. to preserve blank lines), the parser behaviour would silently change.
- **Reproduction:** `ParserBugReproTest::testLexerNeverEmitsConsecutiveEolTokens`.
- **Proposed fix:** none required; document, or decide whether blank lines should be preserved and emit real `EOL`s.

## Notes

- Coverage targets were the lines listed in `var/uncovered-map.txt`; every one of them is now executed (Parser 29,30,152,153,185,229–233,267,293,294,366,389,396,411,418,437,444,449,464,497,502,521,522,528,552,570,575–580,607,608,614,651,677; Evaluator 124,168,171; PromotionCalculator 64,143; PromotionService 225; PromotionTemplateService 125,128).
- Bugs are reported only — **no source files were modified**.
- The three skipped tests (`ParserBugReproTest`) encode intended behaviour that the current source cannot satisfy; un-skipping them fails, as documented per bug.
