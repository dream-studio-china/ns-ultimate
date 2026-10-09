<?php

declare(strict_types=1);

namespace App\Promotion\Service\Dsl;

class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;

    /**
     * Parse tokens into an AST program.
     * @param Token[] $tokens
     */
    public function parse(array $tokens): AstNode
    {
        $this->tokens = $tokens;
        $this->pos = 0;

        $program = AstNode::program();

        while (!$this->isAtEnd()) {
            $token = $this->peek();

            switch ($token->type) {
                case TokenType::EOL:
                    $this->advance();
                    break;
                case TokenType::KEYWORD_TYPE:
                    $program->data['type'] = $this->parseType();
                    break;
                case TokenType::KEYWORD_PHASE:
                    $program->data['phase'] = $this->parsePhase();
                    break;
                case TokenType::KEYWORD_WHEN:
                    $program->children[] = $this->parseWhen();
                    break;
                case TokenType::KEYWORD_DO:
                    $program->children[] = $this->parseDo();
                    break;
                case TokenType::KEYWORD_PRIORITY:
                    $program->data['priority'] = $this->parsePriority();
                    break;
                case TokenType::KEYWORD_FIELDS:
                    $program->children[] = $this->parseFields();
                    break;
                default:
                    throw new DslSyntaxException(
                        "Unexpected keyword '{$token->value}'",
                        $token->line,
                        $token->col
                    );
            }
        }

        return $program;
    }

    private function parseType(): string
    {
        $token = $this->advance(); // KEYWORD_TYPE
        $this->expect(TokenType::COLON);
        $typeToken = $this->advance();
        if ($typeToken->type !== TokenType::IDENTIFIER) {
            throw new DslSyntaxException('Expected promotion type identifier', $typeToken->line, $typeToken->col);
        }
        $this->skipToEol();
        return $typeToken->value;
    }

    private function parsePhase(): int
    {
        $this->advance(); // KEYWORD_PHASE
        $this->expect(TokenType::COLON);
        $phaseToken = $this->advance();
        if ($phaseToken->type !== TokenType::IDENTIFIER) {
            throw new DslSyntaxException("Expected 'inner' or 'outer'", $phaseToken->line, $phaseToken->col);
        }
        $this->skipToEol();
        return $phaseToken->value === 'outer' ? 1 : 0;
    }

    private function parseWhen(): AstNode
    {
        $node = new AstNode('when');
        $this->advance(); // KEYWORD_WHEN
        $this->expect(TokenType::COLON);

        $node->children = $this->parseConditionBlock();

        return $node;
    }

    /** @return AstNode[] */
    private function parseConditionBlock(): array
    {
        $conditions = [];
        $this->consumeEolAfterColon();

        while (!$this->isAtEnd() && $this->isConditionStart()) {
            $token = $this->peek();

            if ($token->type === TokenType::KEYWORD_AND) {
                $conditions[] = $this->parseAndBlock();
            } elseif ($token->type === TokenType::KEYWORD_OR) {
                $conditions[] = $this->parseOrBlock();
            } elseif ($token->type === TokenType::KEYWORD_NOT) {
                $conditions[] = $this->parseNotBlock();
            } else {
                $conditions[] = $this->parseSingleCondition();
            }

            // Only continue if next is EOL
            if ($this->peek()->type === TokenType::EOL) {
                $this->advance();
            } else {
                break;
            }

            // Stop if next token starts a different section
            if ($this->isAtEnd() || $this->isSectionStart()) {
                break;
            }
        }

        return $conditions;
    }

    private function parseAndBlock(): AstNode
    {
        return $this->parseLogicBlock('and');
    }

    private function parseOrBlock(): AstNode
    {
        return $this->parseLogicBlock('or');
    }

    private function parseLogicBlock(string $type): AstNode
    {
        $node = new AstNode($type);
        $this->advance(); // KEYWORD_AND or KEYWORD_OR
        $this->expect(TokenType::COLON);
        $this->consumeEolAfterColon();

        while (!$this->isAtEnd() && !$this->isSectionStart()) {
            $token = $this->peek();

            if ($token->type === TokenType::EOL) {
                $this->advance();
                continue;
            }

            if ($token->type === TokenType::KEYWORD_AND) {
                $node->children[] = $this->parseAndBlock();
            } elseif ($token->type === TokenType::KEYWORD_OR) {
                $node->children[] = $this->parseOrBlock();
            } elseif ($token->type === TokenType::KEYWORD_NOT) {
                $node->children[] = $this->parseNotBlock();
            } else {
                $node->children[] = $this->parseSingleCondition();
            }

            if ($this->peek()->type === TokenType::EOL) {
                $this->advance();
            } else {
                break;
            }
        }

        return $node;
    }

    private function parseNotBlock(): AstNode
    {
        $node = new AstNode('not');
        $this->advance(); // KEYWORD_NOT
        $this->expect(TokenType::COLON);
        $this->consumeEolAfterColon();

        // Consume EOLs before the condition
        while ($this->peek()->type === TokenType::EOL) {
            $this->advance();
        }

        $node->children[] = $this->parseSingleCondition();

        return $node;
    }

    private function parseSingleCondition(): AstNode
    {
        $left = $this->parseOperand();
        $op = $this->parseOperator();
        $right = $this->parseOperand();

        return new AstNode('condition', ['op' => $op, 'left' => $left, 'right' => $right]);
    }

    private function parseOperand(): AstNode
    {
        $token = $this->peek();

        // config.xxx reference
        if ($token->type === TokenType::IDENTIFIER && $token->value === 'config') {
            return $this->parseConfigRef();
        }

        // cart.xxx or user.xxx or item.xxx reference
        if ($token->type === TokenType::IDENTIFIER && in_array($token->value, ['cart', 'user', 'item'], true)) {
            return $this->parsePathRef();
        }

        // Quoted string
        if ($token->type === TokenType::STRING) {
            $this->advance();
            return new AstNode('literal', ['value' => $token->value]);
        }

        // Number
        if ($token->type === TokenType::NUMBER) {
            $this->advance();
            $val = str_contains($token->value, '.') ? (float) $token->value : (int) $token->value;
            return new AstNode('literal', ['value' => $val]);
        }

        throw new DslSyntaxException(
            "Expected operand, got '{$token->value}'",
            $token->line,
            $token->col
        );
    }

    private function parseConfigRef(): AstNode
    {
        $path = $this->parseDottedPath();
        return new AstNode('path', ['value' => $path]);
    }

    private function parsePathRef(): AstNode
    {
        $path = $this->parseDottedPath();
        return new AstNode('path', ['value' => $path]);
    }

    private function parseDottedPath(): string
    {
        $parts = [];
        $token = $this->advance(); // first IDENTIFIER
        $parts[] = $token->value;

        while ($this->peek()->type === TokenType::DOT) {
            $this->advance(); // DOT
            $token = $this->advance(); // IDENTIFIER
            $parts[] = $token->value;
        }

        return implode('.', $parts);
    }

    private function parseOperator(): string
    {
        $token = $this->advance();
        if ($token->type !== TokenType::IDENTIFIER) {
            throw new DslSyntaxException("Expected operator", $token->line, $token->col);
        }

        $validOps = ['>=', '<=', '==', '!=', '>', '<', 'in', 'includes'];
        if (!in_array($token->value, $validOps, true)) {
            throw new DslSyntaxException(
                "Invalid operator '{$token->value}'",
                $token->line,
                $token->col
            );
        }

        return $token->value;
    }

    private function parseDo(): AstNode
    {
        $node = new AstNode('do');
        $this->advance(); // KEYWORD_DO
        $this->expect(TokenType::COLON);
        $this->consumeEolAfterColon();

        while (!$this->isAtEnd() && !$this->isSectionStart()) {
            $token = $this->peek();

            if ($token->type === TokenType::EOL) {
                $this->advance();
                continue;
            }

            $node->children[] = $this->parseAction();

            if ($this->peek()->type === TokenType::EOL) {
                $this->advance();
            }
        }

        return $node;
    }

    private function parseAction(): AstNode
    {
        $verb = $this->advance(); // IDENTIFIER ('discount', 'add', 'free', 'member', 'tiered')

        return match ($verb->value) {
            'discount' => $this->parseDiscountAction(),
            'add' => $this->parseAddAction(),
            'free' => $this->parseFreeShipping(),
            'member' => $this->parseMemberDiscount(),
            'tiered' => $this->parseTieredAction(),
            default => throw new DslSyntaxException(
                "Unknown action '{$verb->value}'",
                $verb->line,
                $verb->col
            ),
        };
    }

    private function parseDiscountAction(): AstNode
    {
        $data = [];

        // target: 'order', 'item' (nth item), or 'items' (matching item groups)
        $target = $this->advance();
        if ($target->type !== TokenType::IDENTIFIER || !in_array($target->value, ['order', 'item', 'items'], true)) {
            throw new DslSyntaxException("Expected 'order', 'item', or 'items'", $target->line, $target->col);
        }
        $data['target'] = $target->value;

        if ($target->value === 'order') {
            $data = array_merge($data, $this->parseOrderDiscountArgs());
        } elseif ($target->value === 'item') {
            $data = array_merge($data, $this->parseItemDiscountArgs());
        } else {
            $data = array_merge($data, $this->parseItemsDiscountArgs());
        }

        return new AstNode('action_discount', $data);
    }

    /** @return array<string, float|int|bool|string> */
    private function parseOrderDiscountArgs(): array
    {
        $data = [];
        $valueToken = $this->advance();

        if ($valueToken->type === TokenType::NUMBER) {
            $data['value'] = str_contains($valueToken->value, '.') ? (float) $valueToken->value : (int) $valueToken->value;

            // Check for % or 'max' keyword
            if ($this->peek()->type === TokenType::PERCENT) {
                $this->advance();
                $data['isPercent'] = true;

                // max cap
                if ($this->peek()->type === TokenType::IDENTIFIER && $this->peek()->value === 'max') {
                    $this->advance();
                    $capToken = $this->advance();
                    if ($capToken->type !== TokenType::NUMBER) {
                        throw new DslSyntaxException('Expected max cap value', $capToken->line, $capToken->col);
                    }
                    $data['maxCap'] = str_contains($capToken->value, '.') ? (float) $capToken->value : (int) $capToken->value;
                }
            }
        } elseif ($valueToken->type === TokenType::IDENTIFIER) {
            if ($valueToken->value === 'config') {
                // Already consumed 'config', now parse .xxx suffix if present
                $val = 'config';
                if ($this->peek()->type === TokenType::DOT) {
                    $this->advance(); // DOT
                    $propToken = $this->advance(); // IDENTIFIER
                    $val .= '.' . $propToken->value;
                }
                $data['value'] = $val;
                // Check for % or 'max' keyword
                if ($this->peek()->type === TokenType::PERCENT) {
                    $this->advance();
                    $data['isPercent'] = true;
                    if ($this->peek()->type === TokenType::IDENTIFIER && $this->peek()->value === 'max') {
                        $this->advance();
                        $capToken = $this->advance();
                        if ($capToken->type !== TokenType::NUMBER) {
                            throw new DslSyntaxException('Expected max cap value', $capToken->line, $capToken->col);
                        }
                        $data['maxCap'] = str_contains($capToken->value, '.') ? (float) $capToken->value : (int) $capToken->value;
                    }
                }
                return $data;
            }
            throw new DslSyntaxException("Expected number for discount amount", $valueToken->line, $valueToken->col);
        }

        return $data;
    }

    /**
     * @return array<string, float|int|bool>
     */
    private function parseItemDiscountArgs(): array
    {
        $data = [];
        // position (nth item)
        $posToken = $this->advance();
        if ($posToken->type !== TokenType::NUMBER) {
            throw new DslSyntaxException('Expected item position number', $posToken->line, $posToken->col);
        }
        $data['position'] = (int) $posToken->value;

        // rate
        $rateToken = $this->advance();
        if ($rateToken->type !== TokenType::NUMBER) {
            throw new DslSyntaxException('Expected discount rate', $rateToken->line, $rateToken->col);
        }
        $data['rate'] = str_contains($rateToken->value, '.') ? (float) $rateToken->value : (int) $rateToken->value;

        if ($this->peek()->type === TokenType::PERCENT) {
            $this->advance();
            $data['isPercent'] = true;
        }

        return $data;
    }

    /**
     * @return array<string, float|int|string|bool>
     */
    private function parseItemsDiscountArgs(): array
    {
        $valueToken = $this->advance();
        if ($valueToken->type === TokenType::NUMBER) {
            $rate = str_contains($valueToken->value, '.') ? (float) $valueToken->value : (int) $valueToken->value;
        } elseif ($valueToken->type === TokenType::IDENTIFIER && $valueToken->value === 'config') {
            $rate = 'config';
            if ($this->peek()->type === TokenType::DOT) {
                $this->advance();
                $property = $this->advance();
                if ($property->type !== TokenType::IDENTIFIER) {
                    throw new DslSyntaxException('Expected config property', $property->line, $property->col);
                }
                $rate .= '.' . $property->value;
            }
        } else {
            throw new DslSyntaxException('Expected discount rate', $valueToken->line, $valueToken->col);
        }

        $this->expect(TokenType::PERCENT);

        return ['rate' => $rate, 'isPercent' => true];
    }

    private function parseAddAction(): AstNode
    {
        $data = ['action' => 'gift'];

        // expect 'gift'
        $gift = $this->advance();
        if ($gift->type !== TokenType::IDENTIFIER || $gift->value !== 'gift') {
            throw new DslSyntaxException("Expected 'gift'", $gift->line, $gift->col);
        }

        while ($this->peek()->type === TokenType::IDENTIFIER) {
            $keyToken = $this->advance();
            $key = rtrim($keyToken->value, ':');

            if ($this->peek()->type === TokenType::COLON) {
                $this->advance(); // COLON
            }

            $valToken = $this->advance();
            $val = $valToken->type === TokenType::NUMBER
                ? (str_contains($valToken->value, '.') ? (float) $valToken->value : (int) $valToken->value)
                : $valToken->value;

            $data[$key] = $val;
        }

        return new AstNode('action_gift', $data);
    }

    private function parseFreeShipping(): AstNode
    {
        $this->advance(); // 'shipping'
        return new AstNode('action_free_shipping', []);
    }

    private function parseMemberDiscount(): AstNode
    {
        // expect 'discount'
        $discWord = $this->advance();
        if ($discWord->type !== TokenType::IDENTIFIER || $discWord->value !== 'discount') {
            throw new DslSyntaxException("Expected 'discount'", $discWord->line, $discWord->col);
        }

        $rateToken = $this->advance();
        if ($rateToken->type !== TokenType::NUMBER) {
            throw new DslSyntaxException('Expected discount rate', $rateToken->line, $rateToken->col);
        }
        $rate = str_contains($rateToken->value, '.') ? (float) $rateToken->value : (int) $rateToken->value;

        $this->expect(TokenType::PERCENT);

        return new AstNode('action_member_discount', ['rate' => $rate]);
    }

    private function parseTieredAction(): AstNode
    {
        $node = new AstNode('action_tiered');
        $this->expect(TokenType::COLON);
        $this->consumeEolAfterColon();

        while (!$this->isAtEnd() && !$this->isSectionStart()) {
            $token = $this->peek();

            if ($token->type === TokenType::EOL) {
                $this->advance();
                continue;
            }

            if ($token->type === TokenType::DASH) {
                $node->children[] = $this->parseTierEntry();
            } else {
                break;
            }

            if ($this->peek()->type === TokenType::EOL) {
                $this->advance();
            }
        }

        return $node;
    }

    private function parseTierEntry(): AstNode
    {
        $this->advance(); // DASH
        $data = [];

        while ($this->peek()->type === TokenType::IDENTIFIER) {
            $keyToken = $this->advance();
            $key = rtrim($keyToken->value, ':');
            $this->expect(TokenType::COLON);

            $valToken = $this->advance();
            $val = $valToken->type === TokenType::NUMBER
                ? (str_contains($valToken->value, '.') ? (float) $valToken->value : (int) $valToken->value)
                : $valToken->value;

            $data[$key] = $val;
        }

        return new AstNode('tier', $data);
    }

    /**
     * @return array<string, string|bool>
     */
    private function parsePriority(): array
    {
        $this->advance(); // KEYWORD_PRIORITY
        $this->expect(TokenType::COLON);

        $exprToken = $this->advance();
        if ($exprToken->type !== TokenType::NUMBER && $exprToken->type !== TokenType::IDENTIFIER) {
            throw new DslSyntaxException('Expected priority value', $exprToken->line, $exprToken->col);
        }

        $value = $exprToken->value;
        if ($value === 'config' && $this->peek()->type === TokenType::DOT) {
            $this->advance();
            $property = $this->advance();
            if ($property->type !== TokenType::IDENTIFIER) {
                throw new DslSyntaxException('Expected config property', $property->line, $property->col);
            }
            $value .= '.' . $property->value;
        }

        $data = ['value' => $value];

        // Check for 'desc'
        if ($this->peek()->type === TokenType::IDENTIFIER && $this->peek()->value === 'desc') {
            $this->advance();
            $data['desc'] = true;
        }

        $this->skipToEol();

        return $data;
    }

    private function parseFields(): AstNode
    {
        $node = new AstNode('fields');
        $this->advance(); // KEYWORD_FIELDS
        $this->expect(TokenType::COLON);
        $this->consumeEolAfterColon();

        while (!$this->isAtEnd() && !$this->isSectionStart()) {
            $token = $this->peek();

            if ($token->type === TokenType::EOL) {
                $this->advance();
                continue;
            }

            if ($token->type === TokenType::IDENTIFIER) {
                $node->children[] = $this->parseFieldDecl();
            } else {
                break;
            }

            if ($this->peek()->type === TokenType::EOL) {
                $this->advance();
            }
        }

        return $node;
    }

    private function parseFieldDecl(): AstNode
    {
        $nameToken = $this->advance(); // IDENTIFIER
        $this->expect(TokenType::COLON);
        $typeToken = $this->advance(); // IDENTIFIER
        $this->expect(TokenType::COLON);
        $labelToken = $this->advance();

        return new AstNode('field', [
            'name' => $nameToken->value,
            'type' => $typeToken->value,
            'label' => $labelToken->value,
        ]);
    }

    // ───────────────────── helpers ─────────────────────

    private function peek(): Token
    {
        return $this->tokens[$this->pos] ?? $this->tokens[count($this->tokens) - 1];
    }

    /** @phpstan-impure */
    private function advance(): Token
    {
        if ($this->isAtEnd()) {
            return $this->tokens[count($this->tokens) - 1];
        }
        return $this->tokens[$this->pos++];
    }

    /** @phpstan-impure */
    private function isAtEnd(): bool
    {
        return $this->pos >= count($this->tokens) || $this->tokens[$this->pos]->type === TokenType::EOF;
    }

    private function expect(TokenType $type): void
    {
        $token = $this->advance();
        if ($token->type !== $type) {
            throw new DslSyntaxException(
                "Expected '{$type->value}', got '{$token->value}'",
                $token->line,
                $token->col
            );
        }
    }

    private function skipToEol(): void
    {
        while (!$this->isAtEnd() && $this->peek()->type !== TokenType::EOL && $this->peek()->type !== TokenType::EOF) {
            $this->advance();
        }
        if ($this->peek()->type === TokenType::EOL) {
            $this->advance();
        }
    }

    private function consumeEolAfterColon(): void
    {
        if ($this->peek()->type === TokenType::EOL) {
            $this->advance();
        }
    }

    private function isConditionStart(): bool
    {
        $token = $this->peek();
        return in_array($token->type, [
            TokenType::KEYWORD_AND,
            TokenType::KEYWORD_OR,
            TokenType::KEYWORD_NOT,
            TokenType::IDENTIFIER,
        ], true);
    }

    /** @phpstan-impure */
    private function isSectionStart(): bool
    {
        $token = $this->peek();
        return in_array($token->type, [
            TokenType::KEYWORD_TYPE,
            TokenType::KEYWORD_PHASE,
            TokenType::KEYWORD_WHEN,
            TokenType::KEYWORD_DO,
            TokenType::KEYWORD_PRIORITY,
            TokenType::KEYWORD_FIELDS,
            TokenType::EOF,
        ], true);
    }
}
