<?php

declare(strict_types=1);

namespace App\Promotion\Service\Dsl;

class AstNode
{
    /**
     * @param array<string, mixed> $data
     * @param AstNode[] $children
     */
    public function __construct(
        public readonly string $type,
        public array $data = [],
        public array $children = [],
    ) {}

    public static function program(): self
    {
        return new self('program');
    }
}
