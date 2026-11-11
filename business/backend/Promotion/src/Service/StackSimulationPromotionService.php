<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Service;

use App\Promotion\Entity\Promotion;
use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Service\PromotionServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;

/**
 * Candidate filter and trace wrapper around the existing promotion service.
 *
 * The real PromotionCalculator still owns phases, stacking, conflict modes,
 * best-price selection and action execution. This adapter only narrows the
 * database candidates in selected mode and supplies validated in-memory drafts
 * in draft mode; it never persists anything.
 */
final class StackSimulationPromotionService implements PromotionServiceInterface
{
    /** @var list<array{promotionId: int|null, promotionName: string, templateName: string|null, type: string|null, phase: int, conflictMode: string, totalAfter: int, changedAmount: int}> */
    private array $trace = [];

    /**
     * @param list<int>|null $allowedIds
     * @param list<array{promotion: Promotion, ast: AstNode}>|null $drafts
     */
    public function __construct(
        private readonly PromotionServiceInterface $inner,
        private readonly ?array $allowedIds,
        private readonly ?array $drafts,
        private readonly PriceCalculationContext $trackedContext,
    ) {}

    /**
     * @param list<int> $excludedIds
     * @return list<Promotion>
     */
    public function getAvailable(PriceCalculationContext $context, ?int $phase = null, array $excludedIds = []): array
    {
        $available = $this->drafts === null
            ? $this->inner->getAvailable($context, $phase, $excludedIds)
            : $this->draftAvailable($context, $phase, $excludedIds);

        if ($this->allowedIds === null) return array_values($available);

        return array_values(array_filter($available, fn (Promotion $promotion): bool => in_array($promotion->getId(), $this->allowedIds, true)));
    }

    /**
     * @param list<int> $excludedIds
     */
    public function getFirstAvailable(PriceCalculationContext $context, ?int $phase = null, array $excludedIds = []): ?Promotion
    {
        return $this->getAvailable($context, $phase, $excludedIds)[0] ?? null;
    }

    public function apply(Promotion $promotion, PriceCalculationContext $context): void
    {
        $before = $context->totalAmount;
        $this->inner->apply($promotion, $context);

        // Best-price candidates are evaluated on cloned contexts. Only the
        // winning application on the tracked context belongs in the trace.
        if ($context !== $this->trackedContext) return;

        $template = $promotion->getTemplate();
        $this->trace[] = [
            'promotionId' => $promotion->getId(),
            'promotionName' => $promotion->getName(),
            'templateName' => $template?->getName(),
            'type' => $template?->getType(),
            'phase' => $template?->getPhase() ?? 0,
            'conflictMode' => $promotion->getConflictMode(),
            'totalAfter' => $context->totalAmount,
            'changedAmount' => $before - $context->totalAmount,
        ];
    }

    /**
     * @param list<int> $excludedIds
     * @return list<Promotion>
     */
    private function draftAvailable(PriceCalculationContext $context, ?int $phase, array $excludedIds): array
    {
        $candidates = [];
        $evaluator = new Evaluator();

        foreach ($this->drafts ?? [] as $draft) {
            $promotion = $draft['promotion'];
            $template = $promotion->getTemplate();
            if ($template === null || !$promotion->isEnabled() || !$template->isEnabled()) continue;
            if ($phase !== null && $template->getPhase() !== $phase) continue;
            if (in_array($promotion->getId(), $excludedIds, true)) continue;
            if (!$this->matches($draft['ast'], $evaluator, $context, $promotion->getConfig() ?? [])) continue;

            $candidates[] = ['promotion' => $promotion, 'priority' => $this->priority($draft['ast'], $promotion->getConfig() ?? [])];
        }

        usort($candidates, static fn (array $left, array $right): int => $right['priority'] <=> $left['priority']);

        return array_map(static fn (array $candidate): Promotion => $candidate['promotion'], $candidates);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function matches(AstNode $ast, Evaluator $evaluator, PriceCalculationContext $context, array $config): bool
    {
        foreach ($ast->children as $section) {
            if ($section->type !== 'when') continue;
            foreach ($section->children as $condition) {
                if (!$evaluator->evaluateCondition($condition, $context, $config)) return false;
            }
        }

        return true;
    }

    /**
     * Mirrors the Core priority resolution used for sorted candidates.
     * @param array<string, mixed> $config
     */
    private function priority(AstNode $ast, array $config): float
    {
        $value = $ast->data['priority']['value'] ?? null;
        if (is_numeric($value)) return (float) $value;
        if (is_string($value) && str_starts_with($value, 'config.')) {
            return (float) ($config[substr($value, 7)] ?? 0);
        }

        return 0.0;
    }

    /** @return list<array{promotionId: int|null, promotionName: string, templateName: string|null, type: string|null, phase: int, conflictMode: string, totalAfter: int, changedAmount: int}> */
    public function trace(): array
    {
        return $this->trace;
    }

    public function update($object, ?array $data = null, bool $noFlush = false): object|false
    {
        return $this->inner->update($object, $data, $noFlush);
    }

    public function get($object, bool $directly = false)
    {
        return $this->inner->get($object, $directly);
    }

    public function list($object = null, $order = null, bool $disableRequest = true)
    {
        return $this->inner->list($object, $order, $disableRequest);
    }

    public function new()
    {
        return $this->inner->new();
    }

    public function remove($object): bool
    {
        return $this->inner->remove($object);
    }

    public function wrapInTransaction(callable $fn): mixed
    {
        return $this->inner->wrapInTransaction($fn);
    }
}
