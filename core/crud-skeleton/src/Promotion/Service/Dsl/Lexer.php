<?php

declare(strict_types=1);

namespace App\Promotion\Service\Dsl;

class Lexer
{
    /** @var array<string, TokenType> */
    private const KEYWORDS = [
        'type' => TokenType::KEYWORD_TYPE,
        'phase' => TokenType::KEYWORD_PHASE,
        'when' => TokenType::KEYWORD_WHEN,
        'do' => TokenType::KEYWORD_DO,
        'priority' => TokenType::KEYWORD_PRIORITY,
        'fields' => TokenType::KEYWORD_FIELDS,
        'and' => TokenType::KEYWORD_AND,
        'or' => TokenType::KEYWORD_OR,
        'not' => TokenType::KEYWORD_NOT,
        'tiered' => TokenType::KEYWORD_TIERED,
    ];

    /**
     * @return Token[]
     */
    public function tokenize(string $input): array
    {
        $tokens = [];
        $lines = explode("\n", $input);
        $lineNum = 0;

        foreach ($lines as $rawLine) {
            $lineNum++;
            $line = rtrim($rawLine);
            $col = 0;
            $len = strlen($line);

            // Skip empty lines and comment-only lines
            if ($line === '' || ltrim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            // Determine indentation level for block structure
            $indent = 0;
            while ($col < $len && $line[$col] === ' ') {
                $indent++;
                $col++;
            }

            // Tokenize the rest of the line
            while ($col < $len) {
                $ch = $line[$col];

                // Skip spaces within line
                if ($ch === ' ') {
                    $col++;
                    continue;
                }

                // Comment mid-line
                if ($ch === '#') {
                    break;
                }

                // Colon
                if ($ch === ':') {
                    $tokens[] = new Token(TokenType::COLON, ':', $lineNum, $col + 1);
                    $col++;
                    continue;
                }

                // Dash (for tiered lists)
                if ($ch === '-') {
                    $tokens[] = new Token(TokenType::DASH, '-', $lineNum, $col + 1);
                    $col++;
                    continue;
                }

                // Percent
                if ($ch === '%') {
                    $tokens[] = new Token(TokenType::PERCENT, '%', $lineNum, $col + 1);
                    $col++;
                    continue;
                }

                // Dot (for ident.prop or config.key references)
                if ($ch === '.') {
                    $tokens[] = new Token(TokenType::DOT, '.', $lineNum, $col + 1);
                    $col++;
                    continue;
                }

                // Numbers (integer or decimal)
                if (ctype_digit($ch) || ($ch === '.' && $col + 1 < $len && ctype_digit($line[$col + 1]))) {
                    $start = $col;
                    if ($ch === '.') $col++;
                    while ($col < $len && ctype_digit($line[$col])) $col++;
                    if ($col < $len && $line[$col] === '.' && $ch !== '.') {
                        $col++;
                        while ($col < $len && ctype_digit($line[$col])) $col++;
                    }
                    $tokens[] = new Token(TokenType::NUMBER, substr($line, $start, $col - $start), $lineNum, $start + 1);
                    continue;
                }

                // Quoted strings
                if ($ch === '"') {
                    $start = $col;
                    $col++;
                    while ($col < $len && $line[$col] !== '"') {
                        $col++;
                    }
                    if ($col >= $len) {
                        throw new DslSyntaxException('Unterminated string', $lineNum, $start + 1);
                    }
                    $col++; // closing quote
                    $value = substr($line, $start + 1, $col - $start - 2);
                    $tokens[] = new Token(TokenType::STRING, $value, $lineNum, $start + 1);
                    continue;
                }

                // Identifiers and keywords (including multi-byte UTF-8)
                if (ctype_alpha($ch) || $ch === '_' || ord($ch) > 127) {
                    $start = $col;
                    while ($col < $len && (
                        ctype_alnum($line[$col]) ||
                        $line[$col] === '_' ||
                        ord($line[$col]) > 127 ||
                        // Allow punctuation in identifiers for field labels etc.
                        $line[$col] === '(' ||
                        $line[$col] === ')'
                    )) {
                        $col++;
                    }
                    $word = substr($line, $start, $col - $start);

                    if (isset(self::KEYWORDS[$word])) {
                        $tokens[] = new Token(self::KEYWORDS[$word], $word, $lineNum, $start + 1);
                    } else {
                        $tokens[] = new Token(TokenType::IDENTIFIER, $word, $lineNum, $start + 1);
                    }
                    continue;
                }

                // Operators
                if ($ch === '>') {
                    if ($col + 1 < $len && $line[$col + 1] === '=') {
                        $tokens[] = new Token(TokenType::IDENTIFIER, '>=', $lineNum, $col + 1);
                        $col += 2;
                    } else {
                        $tokens[] = new Token(TokenType::IDENTIFIER, '>', $lineNum, $col + 1);
                        $col++;
                    }
                    continue;
                }

                if ($ch === '<') {
                    if ($col + 1 < $len && $line[$col + 1] === '=') {
                        $tokens[] = new Token(TokenType::IDENTIFIER, '<=', $lineNum, $col + 1);
                        $col += 2;
                    } else {
                        $tokens[] = new Token(TokenType::IDENTIFIER, '<', $lineNum, $col + 1);
                        $col++;
                    }
                    continue;
                }

                if ($ch === '=') {
                    if ($col + 1 < $len && $line[$col + 1] === '=') {
                        $tokens[] = new Token(TokenType::IDENTIFIER, '==', $lineNum, $col + 1);
                        $col += 2;
                    } else {
                        throw new DslSyntaxException("Unexpected character '='", $lineNum, $col + 1);
                    }
                    continue;
                }

                throw new DslSyntaxException("Unexpected character '{$ch}'", $lineNum, $col + 1);
            }

            // End of line marker
            $tokens[] = new Token(TokenType::EOL, "\n", $lineNum, max($col, 1));
        }

        $tokens[] = new Token(TokenType::EOF, '', $lineNum + 1, 1);

        return $tokens;
    }
}
