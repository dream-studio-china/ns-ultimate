<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseServiceInterface;
use App\Promotion\Entity\PromotionTemplate;

/** @extends BaseServiceInterface<\App\Promotion\Entity\PromotionTemplate> */
interface PromotionTemplateServiceInterface extends BaseServiceInterface
{
    /**
     * Parse DSL text and return AST. Throws DslSyntaxException on failure.
     * @return array{ast: array<string, mixed>|null, errors: list<array{line: int, col: int, message: string}>}
     */
    public function parseDsl(string $dsl): array;

    /**
     * Simulate promotion application against a sample context.
     * @param array<string, mixed> $sampleContext
     * @return array<string, mixed>
     */
    public function simulate(PromotionTemplate $template, array $sampleContext): array;
}
