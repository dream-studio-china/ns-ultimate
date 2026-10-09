<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseService;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Exception\ValidatorException;

/** @extends BaseService<\App\Promotion\Entity\PromotionTemplate> */
final class PromotionTemplateService extends BaseService implements PromotionTemplateServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, PromotionTemplate::class);
    }

    /**
     * @return array{ast: array<string, mixed>|null, errors: list<array{line: int, col: int, message: string}>}
     */
    public function parseDsl(string $dsl): array
    {
        try {
            $lexer = new Lexer();
            $tokens = $lexer->tokenize($dsl);

            $parser = new Parser();
            $ast = $parser->parse($tokens);

            return [
                'ast' => json_decode((string) json_encode($ast), true),
                'errors' => [],
            ];
        } catch (DslSyntaxException $e) {
            return [
                'ast' => null,
                'errors' => [[
                    'line' => $e->line,
                    'col' => $e->col,
                    'message' => $e->getMessage(),
                ]],
            ];
        }
    }

    /**
     * @param array<string, mixed> $sampleContext
     * @return array<string, mixed>
     */
    public function simulate(PromotionTemplate $template, array $sampleContext): array
    {
        $ast = $template->getAstCache();
        if (!$ast) {
            $parseResult = $this->parseDsl($template->getDsl());
            if (!empty($parseResult['errors'])) {
                return [
                    'template_id' => $template->getId(),
                    'type' => $template->getType(),
                    'dsl' => $template->getDsl(),
                    'errors' => $parseResult['errors'],
                ];
            }
            $ast = $parseResult['ast'];
        }

        $context = new PriceCalculationContext($sampleContext['items'] ?? [], $sampleContext['currency'] ?? 'CNY');
        $context->totalAmount = (int) ($sampleContext['totalAmount'] ?? 0);

        $matched = false;
        $actions = [];

        if (isset($ast['children'])) {
            foreach ($ast['children'] as $child) {
                if (($child['type'] ?? '') === 'when') {
                    $evaluator = new Evaluator([]);
                    $allPassed = true;
                    foreach ($child['children'] ?? [] as $cond) {
                        $node = $this->astToNode($cond);
                        if (!$evaluator->evaluateCondition($node, $context, $sampleContext['config'] ?? [])) {
                            $allPassed = false;
                            break;
                        }
                    }
                    $matched = $allPassed;
                }

                if (($child['type'] ?? '') === 'do') {
                    foreach ($child['children'] ?? [] as $action) {
                        $actions[] = $action;
                    }
                }
            }
        }

        return [
            'template_id' => $template->getId(),
            'type' => $template->getType(),
            'dsl' => $template->getDsl(),
            'sampleContext' => $sampleContext,
            'matched' => $matched,
            'actions' => $actions,
        ];
    }

    public function update(mixed $object, ?array $data = null, bool $noFlush = false): object|false
    {
        if (is_array($data) && isset($data['dsl']) && is_string($data['dsl'])) {
            $result = $this->parseDsl($data['dsl']);
            if (!empty($result['errors'])) {
                $error = $result['errors'][0];
                throw new ValidatorException($error['message']);
            }

            $program = $result['ast']['data'] ?? [];
            $type = $data['type'] ?? ($object instanceof PromotionTemplate ? $object->getType() : '');
            $phase = $data['phase'] ?? ($object instanceof PromotionTemplate ? $object->getPhase() : 0);
            if (($program['type'] ?? $type) !== $type) {
                throw new ValidatorException('Template type must match DSL type.');
            }
            if (isset($program['phase']) && $program['phase'] !== $phase) {
                throw new ValidatorException('Template phase must match DSL phase.');
            }

            $data['astCache'] = $result['ast'];
        }

        return parent::update($object, $data, $noFlush);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function astToNode(array $data): \App\Promotion\Service\Dsl\AstNode
    {
        $children = [];
        foreach ($data['children'] ?? [] as $child) {
            $children[] = $this->astToNode($child);
        }
        return new \App\Promotion\Service\Dsl\AstNode(
            $data['type'] ?? 'unknown',
            $data['data'] ?? [],
            $children
        );
    }
}
