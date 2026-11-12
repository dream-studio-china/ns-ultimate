<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Service;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use App\Promotion\Service\PromotionCalculator;
use App\Promotion\Service\PromotionServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;

/**
 * Read-only multi-campaign simulation.
 *
 * Existing campaigns and validated in-memory drafts are passed through the real
 * Core promotion calculator, so phases, ordering, stacking, conflict modes and
 * best-price selection are the production implementation. Nothing is persisted
 * and no order/quote is created.
 */
final class PromotionStackSimulator
{
    private const MODES = ['environment', 'selected', 'drafts'];
    private const CONFLICT_MODES = ['stackable', 'exclusive', 'lock_item', 'best_price'];
    private const LEVELS = ['bronze', 'silver', 'gold', 'platinum', 'diamond'];
    private const MAX_CAMPAIGNS = 20;
    private const MAX_CASES = 4;
    private const MAX_ITEMS = 20;
    private const MAX_UNIT_PRICE = 100000000;
    private const MAX_TOTAL = 2000000000;

    public function __construct(
        private readonly PromotionServiceInterface $promotions,
        private readonly PromotionDraftWorkbench $workbench,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function simulate(array $input): array
    {
        $encoded = json_encode($input);
        if ($encoded === false || strlen($encoded) > 48000) throw new \InvalidArgumentException('The simulation request is invalid or too large.');
        if (array_diff(array_keys($input), ['mode', 'storeCode', 'currency', 'campaignIds', 'drafts', 'cases']) !== []) {
            throw new \InvalidArgumentException('Unsupported simulation fields.');
        }

        $mode = $input['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode must be environment, selected or drafts.');
        }
        if ($mode !== 'drafts' && !array_key_exists('storeCode', $input)) {
            throw new \InvalidArgumentException('storeCode is required for existing-campaign simulation.');
        }
        $storeCode = $input['storeCode'] ?? '';
        if (!is_string($storeCode) || strlen($storeCode) > 50) throw new \InvalidArgumentException('storeCode must contain at most 50 characters.');
        $currency = $input['currency'] ?? 'CNY';
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) throw new \InvalidArgumentException('currency must be a three-letter uppercase code.');

        if ($mode !== 'selected' && array_key_exists('campaignIds', $input)) throw new \InvalidArgumentException('campaignIds is only accepted in selected mode.');
        if ($mode !== 'drafts' && array_key_exists('drafts', $input)) throw new \InvalidArgumentException('drafts is only accepted in drafts mode.');

        $allowedIds = null;
        $drafts = null;
        if ($mode === 'selected') $allowedIds = $this->campaignIds($input['campaignIds'] ?? null);
        if ($mode === 'drafts') $drafts = $this->drafts($input['drafts'] ?? null, $storeCode);

        $cases = $input['cases'] ?? null;
        if (!is_array($cases) || !array_is_list($cases) || count($cases) < 1 || count($cases) > self::MAX_CASES) {
            throw new \InvalidArgumentException('cases must contain between 1 and 4 sample cases.');
        }

        $results = [];
        $verified = true;
        foreach ($cases as $index => $case) {
            $results[] = $this->case($case, $mode, $storeCode, $currency, $allowedIds, $drafts);
            if (!is_array($case) || !array_key_exists('expected', $case) || $results[count($results) - 1]['passed'] !== true) $verified = false;
        }

        return [
            'mode' => $mode,
            'storeCode' => $storeCode,
            'currency' => $currency,
            'evaluatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'cases' => $results,
            'verified' => $verified,
            'warnings' => [
                'Runs the existing promotion calculator only; base price, tax, shipping fees, payment and order persistence are not simulated.',
                'Campaign eligibility (enabled flags, schedule and store) is read at evaluation time. Selected campaigns that are not currently eligible are reported as not applied.',
                'Draft mode assumes each draft is enabled, has no schedule and belongs to this storeCode.',
                'Simulation does not lock records; concurrent campaign changes are not detected.',
                'Nothing is created, updated or saved by this check.',
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function campaignIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > self::MAX_CAMPAIGNS) {
            throw new \InvalidArgumentException('campaignIds must contain between 1 and 20 exact campaign IDs.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('campaignIds must contain positive integers.');
            if (in_array($id, $ids, true)) throw new \InvalidArgumentException('campaignIds must not contain duplicates.');
            $campaign = $this->promotions->get($id);
            if (!$campaign instanceof Promotion || $campaign->getTemplate() === null) throw new \InvalidArgumentException('Campaign '.$id.' was not found.');
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param mixed $value
     * @return list<array{promotion: Promotion, ast: AstNode, key: string, storeCode: string, enabled: bool, templateEnabled: bool, startTime: ?\DateTimeImmutable, endTime: ?\DateTimeImmutable}>
     */
    private function drafts(mixed $value, string $storeCode): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > self::MAX_CAMPAIGNS) {
            throw new \InvalidArgumentException('drafts must contain between 1 and 20 draft campaigns.');
        }

        $drafts = [];
        foreach ($value as $index => $draft) {
            $path = 'drafts.'.$index;
            if (!is_array($draft) || array_diff(array_keys($draft), ['name', 'type', 'phase', 'dsl', 'config', 'conflictMode']) !== []) {
                throw new \InvalidArgumentException($path.' accepts name, type, phase, dsl, config and conflictMode only.');
            }
            $name = $draft['name'] ?? 'Draft '.($index + 1);
            if (!is_string($name) || trim($name) === '' || strlen($name) > 120) throw new \InvalidArgumentException($path.'.name must contain 1-120 characters.');
            $type = $draft['type'] ?? null;
            $phase = $draft['phase'] ?? 0;
            $dsl = $draft['dsl'] ?? null;
            $config = $draft['config'] ?? null;
            $conflictMode = $draft['conflictMode'] ?? 'stackable';
            if (!is_string($type) || !is_int($phase) || !in_array($phase, [0, 1], true)
                || !is_string($dsl) || !is_array($config) || !is_string($conflictMode) || !in_array($conflictMode, self::CONFLICT_MODES, true)) {
                throw new \InvalidArgumentException($path.' has an invalid template, phase, config or conflict mode.');
            }

            $checked = $this->workbench->inspect(['template' => ['type' => $type, 'phase' => $phase, 'dsl' => $dsl], 'config' => $config]);
            if ($checked['valid'] !== true) {
                $details = json_encode(array_slice($checked['errors'] ?? [], 0, 10));
                throw new \InvalidArgumentException($path.' is invalid: '.($details === false ? 'unsupported draft' : $details));
            }

            try {
                $ast = (new Parser())->parse((new Lexer())->tokenize($dsl));
            } catch (DslSyntaxException $error) {
                throw new \InvalidArgumentException($path.'.dsl: '.$error->getMessage());
            }
            $cache = json_decode((string) json_encode($ast), true);
            if (!is_array($cache)) throw new \InvalidArgumentException($path.'.dsl could not be cached.');

            $template = (new PromotionTemplate())
                ->setName($this->text('Draft template '.($index + 1), 120))
                ->setType($type)
                ->setPhase($phase)
                ->setEnabled(true)
                ->setDsl($dsl)
                ->setAstCache($cache);
            $promotion = (new SimulatedPromotion(-$index - 1))
                ->setName($this->text($name, 120))
                ->setTemplate($template)
                ->setStoreCode($storeCode)
                ->setEnabled(true)
                ->setConfig($config)
                ->setConflictMode($conflictMode);
            $drafts[] = ['promotion' => $promotion, 'ast' => $ast, 'key' => 'draft:'.($index + 1), 'storeCode' => $storeCode,
                'enabled' => true, 'templateEnabled' => true, 'startTime' => null, 'endTime' => null];
        }

        return $drafts;
    }

    /**
     * @param mixed $value
     * @param list<int>|null $allowedIds
     * @param list<array{promotion: Promotion, ast: AstNode, key: string, storeCode: string, enabled: bool, templateEnabled: bool, startTime: ?\DateTimeImmutable, endTime: ?\DateTimeImmutable}>|null $drafts
     * @return array<string, mixed>
     */
    private function case(mixed $value, string $mode, string $storeCode, string $currency, ?array $allowedIds, ?array $drafts): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['name', 'items', 'memberLevel', 'expected']) !== []) {
            throw new \InvalidArgumentException('Each case accepts name, items, memberLevel and expected only.');
        }
        $name = $value['name'] ?? 'Sample';
        if (!is_string($name) || strlen($name) > 80) throw new \InvalidArgumentException('Case names must contain at most 80 characters.');

        $items = $value['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('Each case requires 1-20 item groups.');
        }
        $context = new PriceCalculationContext([], $currency);
        $context->storeCode = $storeCode;
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['specificationId', 'quantity', 'unitPrice']) !== []) {
                throw new \InvalidArgumentException('Items accept specificationId, quantity and unitPrice only.');
            }
            $specificationId = $item['specificationId'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unitPrice'] ?? null;
            if (!is_int($specificationId) || $specificationId < 1 || $specificationId > 2147483647
                || !is_int($quantity) || $quantity < 1 || $quantity > 1000
                || !is_int($unitPrice) || $unitPrice < 0 || $unitPrice > self::MAX_UNIT_PRICE) {
                throw new \InvalidArgumentException('Invalid item value; prices use integer minor units.');
            }
            $price = $quantity * $unitPrice;
            if ($price > self::MAX_TOTAL || $context->totalAmount + $price > self::MAX_TOTAL) throw new \InvalidArgumentException('The sample total is too large.');
            $context->items[] = ['specificationId' => $specificationId, 'quantity' => $quantity, 'unitPrice' => $unitPrice, 'price' => $price];
            $context->totalAmount += $price;
        }

        $level = $value['memberLevel'] ?? null;
        if ($level !== null) {
            if (!is_string($level) || !in_array($level, self::LEVELS, true)) throw new \InvalidArgumentException('Invalid memberLevel.');
            $user = new User();
            $context->user = $user->setProfile(new Profile($user, $level));
        }

        $before = $context->totalAmount;
        $provider = new StackSimulationPromotionService($this->promotions, $allowedIds, $drafts, $context, new \DateTimeImmutable());
        try {
            (new PromotionCalculator($provider))->calculate($context);
        } catch (\RuntimeException $error) {
            // An unsupported or malformed strategy configuration must fail closed
            // instead of surfacing an unhandled error or guessing a result.
            throw new \InvalidArgumentException('The campaign configuration cannot be simulated: '.$error->getMessage());
        }
        $trace = $provider->trace();

        $applied = [];
        foreach ($trace as $step) {
            $entry = [
                'promotionName' => $this->text($step['promotionName'], 60),
                'phase' => $step['phase'] === PromotionTemplate::PHASE_OUTER ? 'outer' : 'inner',
                'conflictMode' => $step['conflictMode'],
                'totalAfter' => $step['totalAfter'],
                'changedAmount' => $step['changedAmount'],
            ];
            if ($mode === 'drafts') $entry['draftIndex'] = $step['promotionId'] === null ? null : -$step['promotionId'] - 1;
            else $entry['promotionId'] = $step['promotionId'];
            $applied[] = $entry;
        }

        $actual = [
            'totalAmount' => $context->totalAmount,
            'freeShipping' => ($context->meta['freeShipping'] ?? false) === true,
            'appliedCampaignIds' => array_values(array_filter(array_map(static fn (array $step): ?int => $step['promotionId'], $trace), static fn (?int $id): bool => $id !== null && $id > 0)),
        ];
        $expected = $value['expected'] ?? null;
        [$passed, $differences] = $this->compare($expected, $actual, $mode);

        $result = [
            'name' => $this->text($name, 80),
            'beforeAmount' => $before,
            'afterAmount' => $actual['totalAmount'],
            'discountAmount' => $before - $actual['totalAmount'],
            'applied' => array_slice($applied, 0, 12),
            'appliedCount' => count($applied),
            'freeShipping' => $actual['freeShipping'],
            'itemSummary' => $this->itemSummary($context),
            'expected' => $expected,
            'passed' => $passed,
            'differences' => $differences,
        ];
        if (count($applied) > 12) $result['appliedTruncated'] = true;
        if ($mode === 'selected') {
            $result['notAppliedCampaignIds'] = array_values(array_diff($allowedIds ?? [], $actual['appliedCampaignIds']));
        }
        $bestPrice = $this->bestPrice($context, $mode);
        if ($bestPrice !== null) $result['bestPrice'] = $bestPrice;

        return $result;
    }

    /**
     * @param mixed $expected
     * @param array{totalAmount: int, freeShipping: bool, appliedCampaignIds: list<int>} $actual
     * @return array{0: bool|null, 1: list<array{path: string, expected: mixed, actual: mixed}>}
     */
    private function compare(mixed $expected, array $actual, string $mode): array
    {
        if ($expected === null) return [null, []];
        if (!is_array($expected) || $expected === [] || array_diff(array_keys($expected), ['totalAmount', 'freeShipping', 'appliedCampaignIds']) !== []) {
            throw new \InvalidArgumentException('expected accepts totalAmount, freeShipping and appliedCampaignIds only.');
        }
        if (isset($expected['totalAmount']) && !is_int($expected['totalAmount'])) throw new \InvalidArgumentException('expected.totalAmount must be an integer in minor units.');
        if (isset($expected['freeShipping']) && !is_bool($expected['freeShipping'])) throw new \InvalidArgumentException('expected.freeShipping must be a boolean.');
        if (isset($expected['appliedCampaignIds'])) {
            if ($mode === 'drafts') throw new \InvalidArgumentException('Draft simulation cannot compare appliedCampaignIds; compare totalAmount instead.');
            $ids = $expected['appliedCampaignIds'];
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 40 || array_filter($ids, static fn (mixed $id): bool => !is_int($id) || $id < 1) !== []) {
                throw new \InvalidArgumentException('expected.appliedCampaignIds must be a list of positive integers.');
            }
        }

        $differences = [];
        foreach ($expected as $key => $value) {
            $observed = $actual[$key];
            if ($observed !== $value) $differences[] = ['path' => $key, 'expected' => $value, 'actual' => $observed];
        }

        return [$differences === [], $differences];
    }

    /** @return array{groups: int, giftGroups: int, giftQuantity: int} */
    private function itemSummary(PriceCalculationContext $context): array
    {
        $giftGroups = 0;
        $giftQuantity = 0;
        foreach ($context->items as $item) {
            if (($item['isGift'] ?? false) !== true) continue;
            $giftGroups++;
            $giftQuantity += is_int($item['quantity'] ?? null) ? $item['quantity'] : 0;
        }

        return ['groups' => count($context->items), 'giftGroups' => $giftGroups, 'giftQuantity' => $giftQuantity];
    }

    /** @return array<string, mixed>|null */
    private function bestPrice(PriceCalculationContext $context, string $mode): ?array
    {
        $promotion = $context->meta['promotion'] ?? null;
        $best = is_array($promotion) ? ($promotion['bestPrice'] ?? null) : null;
        if (!is_array($best)) return null;

        $result = [
            'promotionName' => is_string($best['promotionName'] ?? null) ? $this->text($best['promotionName'], 60) : null,
            'totalAmount' => is_int($best['totalAmount'] ?? null) ? $best['totalAmount'] : null,
        ];
        if ($mode === 'drafts') $result['draftIndex'] = is_int($best['promotionId'] ?? null) ? -$best['promotionId'] - 1 : null;
        else $result['promotionId'] = is_int($best['promotionId'] ?? null) ? $best['promotionId'] : null;

        $candidates = [];
        $allCandidates = is_array($best['candidates'] ?? null) ? $best['candidates'] : [];
        foreach (array_slice($allCandidates, 0, 6) as $candidate) {
            if (!is_array($candidate) || !is_int($candidate['promotionId'] ?? null) || !is_int($candidate['totalAmount'] ?? null)) continue;
            $entry = ['totalAmount' => $candidate['totalAmount']];
            if ($mode === 'drafts') $entry['draftIndex'] = -$candidate['promotionId'] - 1;
            else $entry['promotionId'] = $candidate['promotionId'];
            $candidates[] = $entry;
        }
        $result['candidates'] = $candidates;
        if (count($allCandidates) > 6) $result['candidatesTruncated'] = true;

        return $result;
    }

    private function text(string $value, int $limit): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        return function_exists('mb_substr') ? mb_substr(trim($clean), 0, $limit) : substr(trim($clean), 0, $limit);
    }
}
