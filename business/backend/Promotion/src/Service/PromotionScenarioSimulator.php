<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Service;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Service\Dsl\Lexer;
use App\Promotion\Service\Dsl\Parser;
use App\Promotion\Service\PromotionCalculator;
use App\Promotion\Service\PromotionServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Service\Catalog\CatalogResolverInterface;
use App\Trade\Service\Pricing\BasePriceCalculator;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\QuantityCalculator;
use App\Trade\Service\Pricing\TotalAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Read-only scenario simulator for the human promotion workbench.
 *
 * It accepts frozen campaign worlds and cart cases, then executes the real
 * PromotionCalculator. It never persists, flushes, creates an order, mutates a
 * live campaign or calls an external service.
 */
final class PromotionScenarioSimulator
{
    private const LEVELS = ['bronze', 'silver', 'gold', 'platinum', 'diamond'];
    private const CONFLICT_MODES = ['stackable', 'exclusive', 'lock_item', 'best_price'];
    private const MAX_WORLDS = 4;
    private const MAX_CASES = 30;
    private const MAX_CAMPAIGNS = 30;
    private const MAX_ITEMS = 30;
    private const MAX_UNIT_PRICE = 100000000;
    private const MAX_TOTAL = 2000000000;
    private const MAX_SNAPSHOT_CAMPAIGNS = 100;

    /** @var array<string, bool> */
    private array $supportedTypes = [];

    /** @param iterable<\App\Promotion\Strategy\PromotionStrategyInterface> $strategies */
    public function __construct(
        private readonly PromotionServiceInterface $promotions,
        private readonly EntityManagerInterface $em,
        private readonly CatalogResolverInterface $catalogResolver,
        #[AutowireIterator('promotion.strategy')]
        iterable $strategies,
    ) {
        foreach ($strategies as $strategy) $this->supportedTypes[$strategy::supportedType()] = true;
    }

    /** @return array<string, mixed> */
    public function currentSnapshot(string $storeCode): array
    {
        if (strlen($storeCode) > 50) throw new \InvalidArgumentException('storeCode must contain at most 50 characters.');
        $query = $this->em->getRepository(Promotion::class)->createQueryBuilder('p')
            ->where('p.storeCode = :store OR p.storeCode = :global')
            ->setParameter('store', $storeCode)
            ->setParameter('global', '')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(self::MAX_SNAPSHOT_CAMPAIGNS + 1);
        /** @var list<Promotion> $promotions */
        $promotions = $query->getQuery()->getResult();
        $truncated = count($promotions) > self::MAX_SNAPSHOT_CAMPAIGNS;
        $promotions = array_slice($promotions, 0, self::MAX_SNAPSHOT_CAMPAIGNS);
        $campaigns = array_map(fn (Promotion $promotion): array => $this->campaignSnapshot($promotion), $promotions);
        $revision = hash('sha256', (string) json_encode($campaigns));

        return [
            'storeCode' => $storeCode,
            'campaigns' => $campaigns,
            'truncated' => $truncated,
            'revision' => $revision,
            'loadedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed>|null */
    public function orderSnapshot(int $id): ?array
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) return null;

        $items = [];
        foreach ($order->getItems() as $item) {
            $spec = $item->getSpecSnapshot() ?? [];
            $product = $item->getProductSnapshot() ?? [];
            $specificationId = is_int($spec['id'] ?? null) || is_string($spec['id'] ?? null) ? $spec['id'] : $item->getSpecificationUuid();
            $items[] = [
                'specificationId' => $specificationId,
                'specificationUuid' => $item->getSpecificationUuid(),
                'name' => $item->getSpecificationTitle() ?? (is_string($spec['name'] ?? null) ? $spec['name'] : null),
                'productName' => is_string($product['name'] ?? null) ? $product['name'] : null,
                'quantity' => $item->getQuantity(),
                'unitPrice' => $item->getUnitPrice(),
                'price' => $item->getPrice(),
            ];
        }
        $metadata = $order->getMetadata() ?? [];
        $store = is_array($metadata['_store'] ?? null) ? $metadata['_store'] : [];
        $user = $order->getUser();
        $level = $user?->getProfile()?->getLevel();
        if (!in_array($level, self::LEVELS, true)) $level = null;

        return [
            'sourceOrderId' => $order->getId(),
            'sourceOrderUuid' => $order->getUuid(),
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            'currency' => $order->getCurrency(),
            'totalAmount' => $order->getTotalAmount(),
            'storeCode' => is_string($store['code'] ?? null) ? $store['code'] : '',
            'userLevel' => $level,
            'items' => array_slice($items, 0, self::MAX_ITEMS),
            'itemsTruncated' => count($items) > self::MAX_ITEMS,
            'warnings' => ['Imported item prices are historical snapshots. Promotion eligibility and campaigns are not reconstructed; choose the world and simulated time explicitly.'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function userSnapshot(int $id): ?array
    {
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User) return null;
        $level = $user->getProfile()?->getLevel();
        if (!in_array($level, self::LEVELS, true)) $level = null;

        return ['id' => $user->getId(), 'level' => $level];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $encoded = json_encode($input);
        if ($encoded === false || strlen($encoded) > 500000) throw new \InvalidArgumentException('The simulation request is invalid or too large.');
        if (array_diff(array_keys($input), ['worlds', 'cases']) !== []) throw new \InvalidArgumentException('Simulation accepts worlds and cases only.');
        $worlds = $this->worlds($input['worlds'] ?? null);
        $cases = $this->cases($input['cases'] ?? null, array_column($worlds, 'key'));
        $runId = hash('sha256', (string) $encoded);
        $evaluatedAt = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $results = [];
        $passed = 0;
        $failed = 0;
        $errors = 0;
        foreach ($cases as $case) {
            foreach ($worlds as $world) {
                $result = $this->runCase($world, $case);
                $result['worldKey'] = $world['key'];
                $result['caseKey'] = $case['key'];
                $results[] = $result;
                if (($result['status'] ?? '') === 'error') $errors++;
                elseif (($result['passed'] ?? null) === true) $passed++;
                elseif (($result['passed'] ?? null) === false) $failed++;
            }
        }

        return [
            'runId' => $runId,
            'evaluatedAt' => $evaluatedAt,
            'worlds' => array_map(static fn (array $world): array => [
                'key' => $world['key'], 'name' => $world['name'], 'campaignCount' => count($world['campaigns']),
                'errors' => $world['errors'],
            ], $worlds),
            'cases' => array_map(static fn (array $case): array => [
                'key' => $case['key'], 'name' => $case['name'], 'storeCode' => $case['storeCode'],
                'simulatedAt' => $case['simulatedAt']->format(\DATE_ATOM), 'currency' => $case['currency'],
                'pricingMode' => $case['pricingMode'],
            ], $cases),
            'results' => $results,
            'summary' => ['total' => count($results), 'passed' => $passed, 'failed' => $failed, 'errors' => $errors],
            'warnings' => [
                'Promotion-only simulation. Base catalog price is used only in current_catalog mode; tax, shipping fees, payment and order persistence are not simulated.',
                'current_catalog visibility and product availability use the current catalog state; simulatedAt only affects campaign schedules.',
                'Scenario worlds are fixed snapshots. Refresh imported campaigns explicitly before relying on current live configuration.',
                'Eligibility diagnostics are auxiliary; eligible_not_selected has no exact engine reason when only the public calculator is observed.',
                'The run does not lock records; concurrent changes are not detected.',
                'Nothing is created, updated or saved.',
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return list<array{key: string, name: string, campaigns: list<array<string, mixed>>, candidates: list<array<string, mixed>>, errors: list<array{key: string, message: string}>, keysById: array<int, string>}>
     */
    private function worlds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > self::MAX_WORLDS) {
            throw new \InvalidArgumentException('worlds must contain 1-4 configuration sets.');
        }
        $worlds = [];
        $worldKeys = [];
        foreach ($value as $worldIndex => $rawWorld) {
            if (!is_array($rawWorld) || array_diff(array_keys($rawWorld), ['key', 'name', 'campaigns']) !== []) {
                throw new \InvalidArgumentException('Each world accepts key, name and campaigns only.');
            }
            $key = $rawWorld['key'] ?? null;
            $name = $rawWorld['name'] ?? null;
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $key) || in_array($key, $worldKeys, true)) throw new \InvalidArgumentException('World keys must be unique safe identifiers.');
            if (!is_string($name) || trim($name) === '' || strlen($name) > 120) throw new \InvalidArgumentException('World names must contain 1-120 characters.');
            if (!is_array($rawWorld['campaigns']) || count($rawWorld['campaigns']) > self::MAX_CAMPAIGNS) throw new \InvalidArgumentException('Each world accepts at most 30 campaigns.');
            $worldKeys[] = $key;
            $campaigns = [];
            $candidates = [];
            $errors = [];
            $keysById = [];
            $campaignKeys = [];
            foreach ($rawWorld['campaigns'] as $index => $rawCampaign) {
                $campaignKey = is_array($rawCampaign) && is_string($rawCampaign['key'] ?? null) ? $rawCampaign['key'] : '';
                if (!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $campaignKey) || in_array($campaignKey, $campaignKeys, true)) {
                    throw new \InvalidArgumentException('Campaign keys must be unique safe identifiers in world '.$key.'.');
                }
                $campaignKeys[] = $campaignKey;
                $meta = $this->campaignMeta($rawCampaign, $campaignKey, $index, $key);
                $campaigns[] = $meta;
                if ($meta['invalid'] !== null) {
                    $errors[] = ['key' => $campaignKey, 'message' => $meta['invalid']];
                    continue;
                }
                $id = -($index + 1);
                if (isset($keysById[$id])) throw new \InvalidArgumentException('Duplicate synthetic campaign identity.');
                $keysById[$id] = $campaignKey;
                $candidates[] = $meta['candidate'];
            }
            $worlds[] = compact('key', 'name', 'campaigns', 'candidates', 'errors', 'keysById');
        }

        return $worlds;
    }

    /**
     * @param mixed $rawCampaign
     * @return array<string, mixed>
     */
    private function campaignMeta(mixed $rawCampaign, string $key, int $index, string $worldKey): array
    {
        if (!is_array($rawCampaign)) throw new \InvalidArgumentException('Campaign '.$key.' must be an object.');
        $base = [
            'key' => $key, 'name' => 'Campaign '.($index + 1), 'enabled' => true, 'templateEnabled' => true,
            'storeCode' => '', 'startTime' => null, 'endTime' => null, 'conflictMode' => 'stackable',
            'config' => [], 'type' => '', 'phase' => 0, 'dsl' => '', 'invalid' => null, 'candidate' => null, 'ast' => null,
        ];
        $allowed = ['key', 'name', 'enabled', 'templateEnabled', 'storeCode', 'startTime', 'endTime', 'conflictMode', 'config', 'template'];
        if (array_diff(array_keys($rawCampaign), $allowed) !== []) return array_replace($base, ['invalid' => 'Unsupported campaign fields.']);
        $name = $rawCampaign['name'] ?? $base['name'];
        $enabled = $rawCampaign['enabled'] ?? true;
        $templateEnabled = $rawCampaign['templateEnabled'] ?? true;
        $storeCode = $rawCampaign['storeCode'] ?? '';
        $conflictMode = $rawCampaign['conflictMode'] ?? 'stackable';
        $config = $rawCampaign['config'] ?? [];
        $template = $rawCampaign['template'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen($name) > 120) return array_replace($base, ['invalid' => 'Campaign name must contain 1-120 characters.']);
        if (!is_bool($enabled) || !is_bool($templateEnabled) || !is_string($storeCode) || strlen($storeCode) > 50
            || !is_string($conflictMode) || !in_array($conflictMode, self::CONFLICT_MODES, true) || !is_array($config)) {
            return array_replace($base, ['invalid' => 'Invalid campaign flags, store, conflict mode or config.']);
        }
        if (!is_array($template) || array_diff(array_keys($template), ['type', 'phase', 'dsl', 'fields']) !== []) return array_replace($base, ['invalid' => 'A template with type, phase and dsl is required.']);
        $type = $template['type'] ?? null;
        $phase = $template['phase'] ?? 0;
        $dsl = $template['dsl'] ?? null;
        if (!is_string($type) || strlen($type) > 50 || !is_int($phase) || !in_array($phase, [0, 1], true) || !is_string($dsl) || trim($dsl) === '' || strlen($dsl) > 16000) {
            return array_replace($base, ['invalid' => 'Invalid template type, phase or DSL.']);
        }
        if (!isset($this->supportedTypes[$type])) return array_replace($base, ['invalid' => 'Unsupported promotion type: '.$type.'.']);
        $start = $this->dateOrNull($rawCampaign['startTime'] ?? null);
        $end = $this->dateOrNull($rawCampaign['endTime'] ?? null);
        if ($start === false || $end === false) return array_replace($base, ['invalid' => 'Campaign startTime/endTime must be exact ISO dates or null.']);
        if ($start !== null && $end !== null && $start > $end) return array_replace($base, ['invalid' => 'Campaign startTime must not be after endTime.']);
        try {
            $ast = (new Parser())->parse((new Lexer())->tokenize($dsl));
        } catch (DslSyntaxException $error) {
            return array_replace($base, ['invalid' => 'DSL syntax: '.$error->getMessage()]);
        }
        $cache = json_decode((string) json_encode($ast), true);
        if (!is_array($cache)) return array_replace($base, ['invalid' => 'DSL could not be cached.']);
        $promotion = (new SimulatedPromotion(-$index - 1))
            ->setName($name)->setStoreCode($storeCode)->setEnabled($enabled)
            ->setConflictMode($conflictMode)->setConfig($config)->setStartTime($start)->setEndTime($end);
        $promotion->setTemplate((new PromotionTemplate())->setName('Simulation template '.$worldKey.' '.($index + 1))
            ->setType($type)->setPhase($phase)->setEnabled($templateEnabled)->setDsl($dsl)->setAstCache($cache));
        $candidate = ['promotion' => $promotion, 'ast' => $ast, 'key' => $key, 'storeCode' => $storeCode,
            'enabled' => $enabled, 'templateEnabled' => $templateEnabled, 'startTime' => $start, 'endTime' => $end];

        return array_replace($base, ['name' => $name, 'enabled' => $enabled, 'templateEnabled' => $templateEnabled,
            'storeCode' => $storeCode, 'startTime' => $start?->format(\DATE_ATOM), 'endTime' => $end?->format(\DATE_ATOM),
            'conflictMode' => $conflictMode, 'config' => $config, 'type' => $type, 'phase' => $phase, 'dsl' => $dsl,
            'candidate' => $candidate, 'ast' => $ast]);
    }

    /** @param list<string> $worldKeys
     * @return list<array<string, mixed>>
     */
    private function cases(mixed $value, array $worldKeys): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > self::MAX_CASES) {
            throw new \InvalidArgumentException('cases must contain 1-30 cart cases.');
        }
        $cases = [];
        $keys = [];
        foreach ($value as $index => $raw) {
            if (!is_array($raw) || array_diff(array_keys($raw), ['key', 'name', 'storeCode', 'simulatedAt', 'currency', 'pricingMode', 'items', 'user', 'expected', 'expectedByWorld']) !== []) {
                throw new \InvalidArgumentException('Each case accepts key, name, storeCode, simulatedAt, currency, pricingMode, items, user, expected and expectedByWorld only.');
            }
            $key = $raw['key'] ?? 'case:'.($index + 1);
            $name = $raw['name'] ?? 'Case '.($index + 1);
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $key) || in_array($key, $keys, true)) throw new \InvalidArgumentException('Case keys must be unique safe identifiers.');
            if (!is_string($name) || trim($name) === '' || strlen($name) > 120) throw new \InvalidArgumentException('Case names must contain 1-120 characters.');
            $keys[] = $key;
            $storeCode = $raw['storeCode'] ?? '';
            if (!is_string($storeCode) || strlen($storeCode) > 50) throw new \InvalidArgumentException('Invalid case storeCode.');
            $currency = $raw['currency'] ?? 'CNY';
            if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) throw new \InvalidArgumentException('Invalid case currency.');
            $pricingMode = $raw['pricingMode'] ?? 'snapshot';
            if (!in_array($pricingMode, ['snapshot', 'current_catalog'], true)) throw new \InvalidArgumentException('pricingMode must be snapshot or current_catalog.');
            $simulatedAt = $this->dateOrNull($raw['simulatedAt'] ?? null);
            if ($simulatedAt === false || $simulatedAt === null) throw new \InvalidArgumentException('Each case requires an exact simulatedAt date/time.');
            $items = $this->items($raw['items'] ?? null, $pricingMode);
            $user = $this->userInput($raw['user'] ?? null);
            $expected = $this->expected($raw['expected'] ?? null);
            $expectedByWorld = [];
            if (isset($raw['expectedByWorld'])) {
                if (!is_array($raw['expectedByWorld']) || array_is_list($raw['expectedByWorld'])) throw new \InvalidArgumentException('expectedByWorld must be an object keyed by world key.');
                foreach ($raw['expectedByWorld'] as $worldKey => $worldExpected) {
                    if (!is_string($worldKey) || !in_array($worldKey, $worldKeys, true)) throw new \InvalidArgumentException('expectedByWorld contains an unknown world key.');
                    $expectedByWorld[$worldKey] = $this->expected($worldExpected);
                }
            }
            $cases[] = compact('key', 'name', 'storeCode', 'simulatedAt', 'currency', 'pricingMode', 'items', 'user', 'expected', 'expectedByWorld');
        }

        return $cases;
    }

    /** @return list<array<string, mixed>> */
    private function items(mixed $value, string $pricingMode): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > self::MAX_ITEMS) throw new \InvalidArgumentException('Each case requires 1-30 item groups.');
        $items = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['specificationId', 'specificationUuid', 'name', 'quantity', 'unitPrice']) !== []) throw new \InvalidArgumentException('Items accept specificationId, specificationUuid, name, quantity and unitPrice only.');
            $specification = $item['specificationId'] ?? null;
            if (!is_int($specification) && !(is_string($specification) && $specification !== '' && strlen($specification) <= 64)) throw new \InvalidArgumentException('Item specificationId must be a positive integer or a UUID string.');
            if (is_int($specification) && $specification < 1) throw new \InvalidArgumentException('Item specificationId must be positive.');
            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1 || $quantity > 1000) throw new \InvalidArgumentException('Item quantity must be 1-1000.');
            $unitPrice = $item['unitPrice'] ?? null;
            if ($pricingMode === 'snapshot' && (!is_int($unitPrice) || $unitPrice < 0 || $unitPrice > self::MAX_UNIT_PRICE)) throw new \InvalidArgumentException('Snapshot items require integer minor-unit unitPrice.');
            if ($pricingMode === 'current_catalog') $unitPrice = null;
            if (isset($item['name']) && (!is_string($item['name']) || strlen($item['name']) > 120)) throw new \InvalidArgumentException('Item names must contain at most 120 characters.');
            $items[] = ['specificationId' => $specification, 'specificationUuid' => is_string($item['specificationUuid'] ?? null) ? $item['specificationUuid'] : null,
                'name' => is_string($item['name'] ?? null) ? $item['name'] : null, 'quantity' => $quantity, 'unitPrice' => $unitPrice, 'index' => $index];
        }

        return $items;
    }

    /** @return array{mode: string, level: string|null, userId: int|null} */
    private function userInput(mixed $value): array
    {
        if ($value === null) return ['mode' => 'anonymous', 'level' => null, 'userId' => null];
        if (!is_array($value) || array_diff(array_keys($value), ['mode', 'level', 'userId']) !== []) throw new \InvalidArgumentException('user accepts mode, level and userId only.');
        $mode = $value['mode'] ?? 'anonymous';
        if (!in_array($mode, ['anonymous', 'level', 'user'], true)) throw new \InvalidArgumentException('Invalid user mode.');
        $level = $value['level'] ?? null;
        $userId = $value['userId'] ?? null;
        if ($mode === 'level' && (!is_string($level) || !in_array($level, self::LEVELS, true))) throw new \InvalidArgumentException('A valid member level is required.');
        if ($mode === 'user' && (!is_int($userId) || $userId < 1)) throw new \InvalidArgumentException('A positive userId is required.');
        if ($mode !== 'level') $level = null;
        if ($mode !== 'user') $userId = null;

        return ['mode' => $mode, 'level' => $level, 'userId' => $userId];
    }

    /** @return array<string, mixed>|null */
    private function expected(mixed $value): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || array_diff(array_keys($value), ['totalAmount', 'discountAmount', 'freeShipping', 'giftQuantity', 'appliedCampaignKeys', 'items']) !== []) throw new \InvalidArgumentException('expected accepts totalAmount, discountAmount, freeShipping, giftQuantity, appliedCampaignKeys and items only.');
        foreach (['totalAmount', 'discountAmount', 'giftQuantity'] as $key) if (isset($value[$key]) && !is_int($value[$key])) throw new \InvalidArgumentException('expected.'.$key.' must be an integer.');
        if (isset($value['freeShipping']) && !is_bool($value['freeShipping'])) throw new \InvalidArgumentException('expected.freeShipping must be a boolean.');
        if (isset($value['appliedCampaignKeys'])) {
            $keys = $value['appliedCampaignKeys'];
            if (!is_array($keys) || !array_is_list($keys) || count($keys) > self::MAX_CAMPAIGNS || array_filter($keys, static fn (mixed $key): bool => !is_string($key) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $key)) !== []) throw new \InvalidArgumentException('expected.appliedCampaignKeys must be a list of campaign keys.');
        }
        if (isset($value['items'])) {
            $items = $value['items'];
            if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_ITEMS) throw new \InvalidArgumentException('expected.items must contain at most 30 item assertions.');
            foreach ($items as $item) {
                if (!is_array($item) || array_diff(array_keys($item), ['specificationId', 'quantity', 'price', 'isGift']) !== []) throw new \InvalidArgumentException('Each expected item accepts specificationId, quantity, price and isGift only.');
                $specification = $item['specificationId'] ?? null;
                if (!is_int($specification) && !(is_string($specification) && $specification !== '' && strlen($specification) <= 64)) throw new \InvalidArgumentException('Expected item specificationId is invalid.');
                if (!is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || !is_int($item['price'] ?? null) || $item['price'] < 0) throw new \InvalidArgumentException('Expected item quantity/price is invalid.');
                if (isset($item['isGift']) && !is_bool($item['isGift'])) throw new \InvalidArgumentException('Expected item isGift must be a boolean.');
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $world
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    private function runCase(array $world, array $case): array
    {
        $context = new PriceCalculationContext([], (string) $case['currency']);
        $context->storeCode = (string) $case['storeCode'];
        try {
            $context->user = $this->userContext($case['user']);
        } catch (\InvalidArgumentException $error) {
            return $this->errorResult($error->getMessage());
        }

        if ($case['pricingMode'] === 'snapshot') {
            foreach ($case['items'] as $item) {
                $price = (int) $item['quantity'] * (int) $item['unitPrice'];
                if ($price > self::MAX_TOTAL || $context->totalAmount + $price > self::MAX_TOTAL) return $this->errorResult('Item total is too large.');
                $context->items[] = $item + ['price' => $price];
                $context->totalAmount += $price;
            }
        } else {
            $inputItems = [];
            foreach ($case['items'] as $item) {
                $specificationId = $item['specificationId'] ?? null;
                $quantity = $item['quantity'] ?? null;
                if (!is_int($specificationId) && !is_string($specificationId)) return $this->errorResult('Invalid catalog specification.');
                if (!is_int($quantity)) return $this->errorResult('Invalid catalog quantity.');
                $inputItems[] = ['specificationId' => $specificationId, 'quantity' => $quantity];
            }
            $context->inputItems = $inputItems;
            try {
                (new BasePriceCalculator($this->catalogResolver))->calculate($context);
                (new QuantityCalculator())->calculate($context);
                (new TotalAggregator())->calculate($context);
            } catch (\Throwable $error) {
                return $this->errorResult('Catalog price resolution failed: '.$error->getMessage());
            }
        }

        $before = $context->totalAmount;
        $provider = new StackSimulationPromotionService($this->promotions, null, $world['candidates'], $context, $case['simulatedAt']);
        try {
            (new PromotionCalculator($provider))->calculate($context);
        } catch (\RuntimeException $error) {
            return $this->errorResult('The promotion engine could not evaluate this case: '.$error->getMessage(), $before);
        }

        $applied = [];
        foreach ($provider->trace() as $step) {
            $applied[] = [
                'campaignKey' => $step['campaignKey'],
                'name' => $this->text($step['promotionName'], 120),
                'type' => $step['type'],
                'phase' => $step['phase'] === PromotionTemplate::PHASE_OUTER ? 'outer' : 'inner',
                'conflictMode' => $step['conflictMode'],
                'totalAfter' => $step['totalAfter'],
                'changedAmount' => $step['changedAmount'],
            ];
        }
        $bestPriceKeys = $this->bestPriceKeys($context, $world['keysById']);
        $actual = [
            'totalAmount' => $context->totalAmount,
            'discountAmount' => $before - $context->totalAmount,
            'freeShipping' => ($context->meta['freeShipping'] ?? false) === true,
            'giftQuantity' => $this->giftQuantity($context),
            'appliedCampaignKeys' => array_values(array_filter(array_column($applied, 'campaignKey'), static fn (?string $key): bool => $key !== null)),
            'items' => array_map(static fn (array $item): array => [
                'specificationId' => $item['specificationId'], 'quantity' => $item['quantity'], 'price' => $item['price'], 'isGift' => $item['isGift'],
            ], $this->finalItems($context)),
        ];
        $expected = $case['expectedByWorld'][$world['key']] ?? $case['expected'];
        [$passed, $differences] = $this->compare($expected, $actual);

        return [
            'status' => 'ok',
            'beforeAmount' => $before,
            'afterAmount' => $context->totalAmount,
            'discountAmount' => $actual['discountAmount'],
            'applied' => $applied,
            'freeShipping' => $actual['freeShipping'],
            'itemSummary' => ['groups' => count($context->items), 'giftQuantity' => $actual['giftQuantity']],
            'items' => $this->finalItems($context),
            'bestPriceKeys' => $bestPriceKeys,
            'diagnostics' => $this->diagnostics($world, $context, $case, $applied, $bestPriceKeys),
            'expected' => $expected,
            'passed' => $passed,
            'differences' => $differences,
        ];
    }

    /** @param array<string, mixed> $user */
    private function userContext(array $user): ?User
    {
        if ($user['mode'] === 'user') {
            $found = $this->em->find(User::class, $user['userId']);
            if (!$found instanceof User) throw new \InvalidArgumentException('The requested simulation user was not found.');
            return $found;
        }
        if ($user['mode'] === 'level') {
            $created = new User();
            return $created->setProfile(new Profile($created, (string) $user['level']));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $world
     * @param array<string, mixed> $case
     * @param list<array<string, mixed>> $applied
     * @param array<string, bool> $bestPriceKeys
     * @return list<array<string, mixed>>
     */
    private function diagnostics(array $world, PriceCalculationContext $context, array $case, array $applied, array $bestPriceKeys): array
    {
        $appliedByKey = [];
        foreach ($applied as $item) $appliedByKey[$item['campaignKey']] = $item;
        $evaluator = new Evaluator();
        $diagnostics = [];
        foreach ($world['campaigns'] as $campaign) {
            $entry = ['campaignKey' => $campaign['key'], 'name' => $campaign['name'], 'status' => 'eligible_not_selected'];
            if ($campaign['invalid'] !== null) {
                $entry += ['status' => 'invalid', 'message' => $campaign['invalid']];
            } elseif (!$campaign['templateEnabled']) {
                $entry['status'] = 'template_disabled';
            } elseif (!$campaign['enabled']) {
                $entry['status'] = 'disabled';
            } elseif ($campaign['storeCode'] !== '' && $campaign['storeCode'] !== $case['storeCode']) {
                $entry['status'] = 'store_mismatch';
            } elseif ($campaign['startTime'] !== null && $campaign['startTime'] > $case['simulatedAt']) {
                $entry['status'] = 'not_started';
            } elseif ($campaign['endTime'] !== null && $campaign['endTime'] < $case['simulatedAt']) {
                $entry['status'] = 'ended';
            } elseif ($campaign['ast'] instanceof AstNode && !$this->matches($campaign['ast'], $evaluator, $context, $campaign['config'])) {
                $entry['status'] = 'condition_unmet';
                $entry['message'] = 'One or more DSL conditions do not match this cart.';
            } elseif (isset($appliedByKey[$campaign['key']])) {
                $entry['status'] = $appliedByKey[$campaign['key']]['changedAmount'] === 0 ? 'applied_no_change' : 'applied';
                $entry['totalAfter'] = $appliedByKey[$campaign['key']]['totalAfter'];
                $entry['changedAmount'] = $appliedByKey[$campaign['key']]['changedAmount'];
            } elseif ($campaign['conflictMode'] === 'best_price' && isset($bestPriceKeys[$campaign['key']])) {
                $entry['status'] = 'best_price_not_selected';
                $entry['message'] = 'Participated in best-price comparison but was not the cheapest candidate.';
            } else {
                $entry['message'] = 'Conditions match, but the engine did not select this campaign (priority, conflict mode, best-price comparison or iteration limit).';
            }
            $diagnostics[] = $entry;
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return array{0: bool|null, 1: list<array{path: string, expected: mixed, actual: mixed}>}
     */
    private function compare(?array $expected, array $actual): array
    {
        if ($expected === null) return [null, []];
        $differences = [];
        foreach ($expected as $key => $value) {
            if ($key === 'items') {
                foreach ($value as $index => $expectedItem) {
                    $found = false;
                    foreach ($actual['items'] as $actualItem) {
                        if ($actualItem['specificationId'] !== $expectedItem['specificationId']
                            || $actualItem['quantity'] !== $expectedItem['quantity']
                            || $actualItem['price'] !== $expectedItem['price']
                            || isset($expectedItem['isGift']) && $actualItem['isGift'] !== $expectedItem['isGift']) continue;
                        $found = true;
                        break;
                    }
                    if (!$found) $differences[] = ['path' => 'items.'.$index, 'expected' => $expectedItem, 'actual' => $actual['items']];
                }
                continue;
            }
            if ($actual[$key] !== $value) $differences[] = ['path' => $key, 'expected' => $value, 'actual' => $actual[$key]];
        }

        return [$differences === [], $differences];
    }

    /**
     * @param array<int, string> $keysById
     * @return array<string, bool>
     */
    private function bestPriceKeys(PriceCalculationContext $context, array $keysById): array
    {
        $meta = $context->meta['promotion'] ?? null;
        $best = is_array($meta) ? ($meta['bestPrice'] ?? null) : null;
        $keys = [];
        foreach (is_array($best) && is_array($best['candidates'] ?? null) ? $best['candidates'] : [] as $candidate) {
            $id = is_array($candidate) ? ($candidate['promotionId'] ?? null) : null;
            if (is_int($id) && isset($keysById[$id])) $keys[$keysById[$id]] = true;
        }

        return $keys;
    }

    /** @return array<string, mixed> */
    private function errorResult(string $message, ?int $before = null): array
    {
        return ['status' => 'error', 'error' => $this->text($message, 500), 'beforeAmount' => $before,
            'afterAmount' => null, 'discountAmount' => null, 'applied' => [], 'freeShipping' => false,
            'itemSummary' => ['groups' => 0, 'giftQuantity' => 0], 'items' => [], 'diagnostics' => [],
            'expected' => null, 'passed' => false, 'differences' => []];
    }

    /** @return list<array<string, mixed>> */
    private function finalItems(PriceCalculationContext $context): array
    {
        $items = [];
        foreach (array_slice($context->items, 0, self::MAX_ITEMS) as $item) {
            $items[] = [
                'specificationId' => $item['specificationId'] ?? null,
                'name' => is_string($item['specificationName'] ?? null) ? $this->text($item['specificationName'], 120) : null,
                'quantity' => is_int($item['quantity'] ?? null) ? $item['quantity'] : null,
                'unitPrice' => is_int($item['unitPrice'] ?? null) ? $item['unitPrice'] : null,
                'price' => is_int($item['price'] ?? null) ? $item['price'] : null,
                'isGift' => ($item['isGift'] ?? false) === true,
            ];
        }

        return $items;
    }

    private function giftQuantity(PriceCalculationContext $context): int
    {
        $quantity = 0;
        foreach ($context->items as $item) if (($item['isGift'] ?? false) === true && is_int($item['quantity'] ?? null)) $quantity += $item['quantity'];

        return $quantity;
    }

    /** @param array<string, mixed> $config */
    private function matches(AstNode $ast, Evaluator $evaluator, PriceCalculationContext $context, array $config): bool
    {
        foreach ($ast->children as $section) {
            if ($section->type !== 'when') continue;
            foreach ($section->children as $condition) if (!$evaluator->evaluateCondition($condition, $context, $config)) return false;
        }

        return true;
    }

    private function dateOrNull(mixed $value): \DateTimeImmutable|false|null
    {
        if ($value === null) return null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', trim($value))) return false;
        try {
            $date = new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return false;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        return $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0) ? false : $date;
    }

    /** @return array<string, mixed> */
    private function campaignSnapshot(Promotion $promotion): array
    {
        $template = $promotion->getTemplate();
        return [
            'key' => 'campaign:'.$promotion->getId(),
            'id' => $promotion->getId(),
            'name' => $this->text($promotion->getName(), 120),
            'enabled' => $promotion->isEnabled(),
            'templateEnabled' => $template?->isEnabled() ?? false,
            'storeCode' => $promotion->getStoreCode(),
            'startTime' => $promotion->getStartTime()?->format(\DATE_ATOM),
            'endTime' => $promotion->getEndTime()?->format(\DATE_ATOM),
            'conflictMode' => $promotion->getConflictMode(),
            'config' => $promotion->getConfig() ?? [],
            'template' => $template === null ? null : [
                'type' => $template->getType(), 'phase' => $template->getPhase(), 'dsl' => $template->getDsl(), 'fields' => $template->getFields(),
            ],
        ];
    }

    private function text(string $value, int $limit): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        return function_exists('mb_substr') ? mb_substr(trim($clean), 0, $limit) : substr(trim($clean), 0, $limit);
    }
}
