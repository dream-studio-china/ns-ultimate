<?php

declare(strict_types=1);

namespace App\Promotion\Service\Dsl;

enum TokenType: string
{
    case KEYWORD_TYPE = 'type';
    case KEYWORD_PHASE = 'phase';
    case KEYWORD_WHEN = 'when';
    case KEYWORD_DO = 'do';
    case KEYWORD_PRIORITY = 'priority';
    case KEYWORD_FIELDS = 'fields';
    case KEYWORD_AND = 'and';
    case KEYWORD_OR = 'or';
    case KEYWORD_NOT = 'not';
    case KEYWORD_TIERED = 'tiered';
    case COLON = ':';
    case DASH = '-';
    case DOT = '.';
    case PERCENT = '%';
    case STRING = 'STRING';
    case NUMBER = 'NUMBER';
    case IDENTIFIER = 'IDENTIFIER';
    case EOL = 'EOL';
    case EOF = 'EOF';
}
