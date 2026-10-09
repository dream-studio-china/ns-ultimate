<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseService;
use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Repository\PromotionRepository;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Strategy\PromotionStrategyInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Promotion\Entity\Promotion> */
final class PromotionService extends BaseService implements PromotionServiceInterface
{
    /** @param iterable<PromotionStrategyInterface> $strategies */
    public function __construct(
        ContainerInterface $container,
        #[AutowireIterator('promotion.strategy')]
        private readonly iterable $strategies = [],
    ) {
        parent::__construct($container, Promotion::class);
    }

    /**
     * @param list<int> $excludedIds
     * @return Promotion[]
     */
    public function getAvailable(
        PriceCalculationContext $context,
        ?int $phase = null,
        array $excludedIds = []
    ): array {
        // A missing store only permits explicitly global campaigns (empty code),
        // never every store's campaigns.
        $storeCode = $context->storeCode ?? '';

        $now = new \DateTimeImmutable();

        /** @var Promotion[] $promotions */
        $promotions = $this->rep instanceof PromotionRepository
            ? $this->rep->findActiveForStore($storeCode, $now, $phase, $excludedIds)
            : $this->rep->findBy(['enabled' => true, 'storeCode' => $storeCode]);

        $evaluator = $this->createEvaluator();

        $filtered = array_filter($promotions, function (Promotion $promotion) use ($context, $evaluator, $now, $phase, $excludedIds) {
            $template = $promotion->getTemplate();
            if (!$template) {
                return false;
            }

            // Kept here as a defence-in-depth check and for repository test doubles.
            if (!$promotion->isEnabled() || !$template->isEnabled()) {
                return false;
            }
            if ($phase !== null && $template->getPhase() !== $phase) {
                return false;
            }
            if (($promotion->getStartTime() && $promotion->getStartTime() > $now)
                || ($promotion->getEndTime() && $promotion->getEndTime() < $now)
                || in_array($promotion->getId(), $excludedIds, true)) {
                return false;
            }

            return $this->evaluateDslConditions($template, $evaluator, $context, $promotion->getConfig() ?? []);
        });

        // Sort by priority from DSL AST
        $sorted = array_values($filtered);
        usort($sorted, function (Promotion $a, Promotion $b) {
            return $this->getPriority($b) <=> $this->getPriority($a);
        });

        return $sorted;
    }

    /**
     * @param list<int> $excludedIds
     */
    public function getFirstAvailable(
        PriceCalculationContext $context,
        ?int $phase = null,
        array $excludedIds = []
    ): ?Promotion {
        $available = $this->getAvailable($context, $phase, $excludedIds);
        return $available[0] ?? null;
    }

    public function apply(
        Promotion $promotion,
        PriceCalculationContext $context
    ): void {
        $template = $promotion->getTemplate();
        if (!$template) {
            return;
        }

        $ast = $template->getAstCache();
        if (!$ast) {
            return;
        }

        $config = $promotion->getConfig() ?? [];
        $evaluator = $this->createEvaluator();

        // Find the 'do' actions
        $actions = $this->findDoActions($ast);

        if (!empty($actions)) {
            $evaluator->executeActions($actions, $template->getType(), $context, $config);
        }
    }

    /** @param array<string, mixed> $config */
    private function evaluateDslConditions(
        PromotionTemplate $template,
        Evaluator $evaluator,
        PriceCalculationContext $context,
        array $config
    ): bool {
        $ast = $template->getAstCache();
        if (!$ast) {
            return true; // No DSL = always match
        }

        $whenNode = $this->findWhenNode($ast);
        if (!$whenNode) {
            return true;
        }

        foreach ($whenNode['children'] ?? [] as $condition) {
            $node = $this->arrayToAstNode($condition);
            if (!$evaluator->evaluateCondition($node, $context, $config)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ast
     * @return array<string, mixed>|null
     */
    private function findWhenNode(array $ast): ?array
    {
        foreach ($ast['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'when') {
                return $child;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $ast
     * @return \App\Promotion\Service\Dsl\AstNode[]
     */
    private function findDoActions(array $ast): array
    {
        $actions = [];
        foreach ($ast['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'do') {
                foreach ($child['children'] ?? [] as $action) {
                    $actions[] = $this->arrayToAstNode($action);
                }
            }
        }
        return $actions;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToAstNode(array $data): \App\Promotion\Service\Dsl\AstNode
    {
        $children = [];
        foreach ($data['children'] ?? [] as $child) {
            $children[] = $this->arrayToAstNode($child);
        }

        $nodeData = $data['data'] ?? [];
        foreach (['left', 'right'] as $operand) {
            if (isset($nodeData[$operand]) && is_array($nodeData[$operand]) && isset($nodeData[$operand]['type'])) {
                $nodeData[$operand] = $this->arrayToAstNode($nodeData[$operand]);
            }
        }

        return new \App\Promotion\Service\Dsl\AstNode(
            $data['type'] ?? 'unknown',
            $nodeData,
            $children
        );
    }

    private function getPriority(Promotion $promotion): float
    {
        $ast = $promotion->getTemplate()?->getAstCache();
        if (!$ast) {
            return 0.0;
        }

        $priority = $ast['data']['priority'] ?? null;
        if (!$priority) {
            return 0.0;
        }

        $value = $priority['value'] ?? 0;
        if (is_numeric($value)) {
            return (float) $value;
        }

        // config.xxx reference — resolve from promotion config
        if (is_string($value) && str_starts_with($value, 'config.')) {
            $key = substr($value, 7);
            $config = $promotion->getConfig() ?? [];
            return (float) ($config[$key] ?? 0);
        }

        return 0.0;
    }

    private function createEvaluator(): Evaluator
    {
        $strategies = is_array($this->strategies)
            ? $this->strategies
            : iterator_to_array($this->strategies);

        return new Evaluator($strategies);
    }
}
