<?php

declare(strict_types=1);

namespace App\Promotion\Service\Dsl;

class DslSyntaxException extends \RuntimeException
{
    public int $line;
    public int $col;

    public function __construct(
        string $message,
        int $line,
        int $col,
    ) {
        parent::__construct(sprintf('[%d:%d] %s', $line, $col, $message));
        $this->line = $line;
        $this->col = $col;
    }
}
