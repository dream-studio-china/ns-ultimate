<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service;

use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionService;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AllowMockObjectsWithoutExpectations]
final class PromotionServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private EntityRepository $rep;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rep = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')->willReturnCallback(function (string $className) {
            if ($className === Promotion::class) {
                return $this->rep;
            }
            return $this->createMock(EntityRepository::class);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($logger, $tokenStorage) {
                return match ($id) {
                    'doctrine.orm.entity_manager' => $this->em,
                    'logger' => $logger,
                    'security.token_storage' => $tokenStorage,
                    default => null,
                };
            });
        $this->container->method('has')->willReturn(true);
    }

    private function createService(array $strategies = []): PromotionService
    {
        return new PromotionService($this->container, $strategies);
    }

    private function createPromotionTemplate(string $type = PromotionTemplate::TYPE_FULL_REDUCTION, int $phase = PromotionTemplate::PHASE_INNER, bool $enabled = true, ?array $astCache = null): PromotionTemplate
    {
        $template = new PromotionTemplate();
        $template->setName('Test Template');
        $template->setType($type);
        $template->setPhase($phase);
        $template->setEnabled($enabled);
        if ($astCache !== null) {
            $template->setAstCache($astCache);
        }
        return $template;
    }

    private function createPromotion(string $name = 'Test Promotion', ?PromotionTemplate $template = null, string $storeCode = '', bool $enabled = true, ?\DateTimeImmutable $startTime = null, ?\DateTimeImmutable $endTime = null, ?array $config = null): Promotion
    {
        $p = new Promotion();
        $p->setName($name);
        $p->setStoreCode($storeCode);
        $p->setEnabled($enabled);
        $p->setStartTime($startTime);
        $p->setEndTime($endTime);
        $p->setConfig($config);

        if ($template !== null) {
            $p->setTemplate($template);
        }

        return $p;
    }

    // ──────────────────────────── getAvailable ────────────────────────────

    public function testGetAvailableReturnsEmptyWhenNoneFound(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $this->rep->method('findBy')->with(['enabled' => true, 'storeCode' => ''])->willReturn([]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(0, $result);
    }

    public function testGetAvailableFiltersByStoreCode(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = 'store-a';

        $this->rep->expects(self::once())
            ->method('findBy')
            ->with(['enabled' => true, 'storeCode' => 'store-a'])
            ->willReturn([]);

        $this->createService()->getAvailable($context);
    }

    public function testGetAvailableWithoutStoreCode(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $this->rep->expects(self::once())
            ->method('findBy')
            ->with(['enabled' => true, 'storeCode' => ''])
            ->willReturn([]);

        $this->createService()->getAvailable($context);
    }

    public function testGetAvailablePromotionNoTemplate(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $promotion = $this->createPromotion('No Template');

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(0, $result);
    }

    public function testGetAvailablePromotionEnabled(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $promotion = $this->createPromotion('Enabled', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
        self::assertSame('Enabled', $result[0]->getName());
    }

    public function testGetAvailableFiltersDisabledPromotion(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $promotion = $this->createPromotion('Disabled', $template, '', false);

        // findBy query has enabled => true, mock always returns the array regardless
        // The disabled promotion passes through findBy but has isEnabled() = false
        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        // Defence-in-depth filtering rejects disabled rows even when a repository
        // test double returns them.
        self::assertCount(0, $result);
    }

    public function testGetAvailableFiltersDisabledTemplate(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate(PromotionTemplate::TYPE_FULL_REDUCTION, PromotionTemplate::PHASE_INNER, false);
        $promotion = $this->createPromotion('With Disabled Template', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(0, $result);
    }

    public function testGetAvailableFiltersByPhase(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $templateInner = $this->createPromotionTemplate(PromotionTemplate::TYPE_FULL_REDUCTION, PromotionTemplate::PHASE_INNER);
        $templateOuter = $this->createPromotionTemplate(PromotionTemplate::TYPE_FREE_SHIPPING, PromotionTemplate::PHASE_OUTER);

        $promotionInner = $this->createPromotion('Inner Promo', $templateInner);
        $promotionOuter = $this->createPromotion('Outer Promo', $templateOuter);

        $this->rep->method('findBy')->willReturn([$promotionInner, $promotionOuter]);

        $result = $this->createService()->getAvailable($context, PromotionTemplate::PHASE_INNER);

        self::assertCount(1, $result);
        self::assertSame('Inner Promo', $result[0]->getName());
    }

    public function testGetAvailableFiltersByPhaseOuter(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $templateInner = $this->createPromotionTemplate(PromotionTemplate::TYPE_FULL_REDUCTION, PromotionTemplate::PHASE_INNER);
        $templateOuter = $this->createPromotionTemplate(PromotionTemplate::TYPE_FREE_SHIPPING, PromotionTemplate::PHASE_OUTER);

        $promotionInner = $this->createPromotion('Inner Promo', $templateInner);
        $promotionOuter = $this->createPromotion('Outer Promo', $templateOuter);

        $this->rep->method('findBy')->willReturn([$promotionInner, $promotionOuter]);

        $result = $this->createService()->getAvailable($context, PromotionTemplate::PHASE_OUTER);

        self::assertCount(1, $result);
        self::assertSame('Outer Promo', $result[0]->getName());
    }

    public function testGetAvailableFiltersByStartTime(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $future = new \DateTimeImmutable('+1 day');
        $promotion = $this->createPromotion('Future Promo', $template, '', true, $future);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(0, $result);
    }

    public function testGetAvailableFiltersByEndTime(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $past = new \DateTimeImmutable('-1 day');
        $promotion = $this->createPromotion('Expired Promo', $template, '', true, null, $past);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(0, $result);
    }

    public function testGetAvailablePromotionWithinTimeRange(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $past = new \DateTimeImmutable('-1 day');
        $future = new \DateTimeImmutable('+1 day');
        $promotion = $this->createPromotion('Active Promo', $template, '', true, $past, $future);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
        self::assertSame('Active Promo', $result[0]->getName());
    }

    public function testGetAvailableWithoutStartAndEndTime(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $promotion = $this->createPromotion('Always Active', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
    }

    public function testGetAvailableFiltersByDslCondition(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'condition',
                            'data' => [
                                'op' => '>=',
                                'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('DSL Match', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
    }

    public function testGetAvailableWithWhenNodeButNoConditions(): void
    {
        // When node exists but has no children — should pass (empty conditions = match)
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [],
                ],
            ],
        ];
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('Empty When', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
    }

    public function testGetAvailablePromotionWithoutAst(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $promotion = $this->createPromotion('No AST', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
    }

    private function createPriorityAst($value): array
    {
        return ['type' => 'program', 'data' => ['priority' => ['value' => $value]], 'children' => []];
    }

    public function testGetAvailableSortsByPriority(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;
        $context->totalAmount = 50000;

        $template1 = $this->createPromotionTemplate();
        $template1->setAstCache($this->createPriorityAst(100));
        $promotion1 = $this->createPromotion('Priority 100', $template1);

        $template2 = $this->createPromotionTemplate();
        $template2->setAstCache($this->createPriorityAst(500));
        $promotion2 = $this->createPromotion('Priority 500', $template2);

        $template3 = $this->createPromotionTemplate();
        $template3->setAstCache($this->createPriorityAst(300));
        $promotion3 = $this->createPromotion('Priority 300', $template3);

        $this->rep->method('findBy')->willReturn([$promotion1, $promotion2, $promotion3]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(3, $result);
        self::assertSame('Priority 500', $result[0]->getName());
        self::assertSame('Priority 300', $result[1]->getName());
        self::assertSame('Priority 100', $result[2]->getName());
    }

    // ──────────────────────────── getFirstAvailable ────────────────────────────

    public function testGetFirstAvailableReturnsFirstMatch(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $promotion1 = $this->createPromotion('First', $template);
        $promotion2 = $this->createPromotion('Second', $template);

        $this->rep->method('findBy')->willReturn([$promotion1, $promotion2]);

        $result = $this->createService()->getFirstAvailable($context);

        self::assertNotNull($result);
        self::assertSame('First', $result->getName());
    }

    public function testGetFirstAvailableReturnsNullWhenNone(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $this->rep->method('findBy')->willReturn([]);

        $result = $this->createService()->getFirstAvailable($context);

        self::assertNull($result);
    }

    public function testGetFirstAvailableReturnsNullWhenAllFiltered(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate(PromotionTemplate::TYPE_FULL_REDUCTION, PromotionTemplate::PHASE_OUTER);
        $promotion = $this->createPromotion('Outer Only', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getFirstAvailable($context, PromotionTemplate::PHASE_INNER);

        self::assertNull($result);
    }

    // ──────────────────────────── apply ────────────────────────────

    public function testApplyWithNoTemplate(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $promotion = $this->createPromotion('No Template');

        $this->createService()->apply($promotion, $context);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithNoAstCache(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $template = $this->createPromotionTemplate();
        $promotion = $this->createPromotion('No AST', $template);

        $this->createService()->apply($promotion, $context);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyExecutesActions(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $ast = [
            'type' => 'program',
            'data' => ['type' => 'full_reduction'],
            'children' => [
                [
                    'type' => 'do',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'action_discount',
                            'data' => ['target' => 'order', 'value' => 10.00],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $template = $this->createPromotionTemplate(PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('With Actions', $template);

        $strategy = new \App\Promotion\Strategy\FullReductionStrategy();
        $service = $this->createService([$strategy]);

        $service->apply($promotion, $context);

        self::assertSame(49000, $context->totalAmount);
    }

    public function testApplyWithEmptyDoChildren(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'do',
                    'data' => [],
                    'children' => [],
                ],
            ],
        ];

        $template = $this->createPromotionTemplate();
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('Empty Do', $template);

        $service = $this->createService();
        $service->apply($promotion, $context);

        self::assertSame(50000, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testApplyWithNoDoNode(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [],
        ];

        $template = $this->createPromotionTemplate();
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('No Do', $template);

        $service = $this->createService();
        $service->apply($promotion, $context);

        self::assertSame(50000, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testGetAvailableWithConfigRefPriority(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;
        $context->totalAmount = 50000;

        $template = $this->createPromotionTemplate();
        $template->setAstCache($this->createPriorityAst('config.priority'));
        $promotion = $this->createPromotion('Config Priority', $template, '', true, null, null, ['priority' => 999]);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
    }

    // ──────────────────── getAvailable with DSL and/or conditions ────────────────────

    public function testGetAvailableWithAndConditionMatches(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->items = [['unitPrice' => 10], ['unitPrice' => 20]];
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'and',
                            'data' => [],
                            'children' => [
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '>=',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '>=',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.items.count'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 2], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('AND Match', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(1, $result);
    }

    public function testGetAvailableWithAndConditionFails(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 100;
        $context->items = [];
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'and',
                            'data' => [],
                            'children' => [
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '>=',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '==',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('AND Fail', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);

        self::assertCount(0, $result);
    }

    public function testGetAvailableWithOrConditionMatchesSecond(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 100;
        $context->items = [['unitPrice' => 100]];
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'or',
                            'data' => [],
                            'children' => [
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '>=',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '>=',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.items.count'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 1], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('OR Match Second', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);
        self::assertCount(1, $result);
    }

    public function testGetAvailableWithNotConditionPasses(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 500;
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'not',
                            'data' => [],
                            'children' => [
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '==',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('NOT Pass', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);
        self::assertCount(1, $result);
    }

    public function testGetAvailableWithNotConditionFails(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $ast = [
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'not',
                            'data' => [],
                            'children' => [
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '>=',
                                        'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('NOT Fail', $template);

        $this->rep->method('findBy')->willReturn([$promotion]);

        $result = $this->createService()->getAvailable($context);
        self::assertCount(0, $result);
    }

    // ──────────────────── apply with null template ────────────────────

    #[Group('low-value')]
    public function testApplyWithNullTemplateDoesNothing(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $promotion = new Promotion();
        $promotion->setName('No Template Attached');
        // template is null by default

        $this->createService()->apply($promotion, $context);

        self::assertSame(50000, $context->totalAmount);
    }

    // ──────────────────── apply with actions but no matching strategy ────────────────────

    public function testApplyExecutesMultipleActions(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 100000;

        $ast = [
            'type' => 'program',
            'data' => ['type' => 'full_reduction'],
            'children' => [
                [
                    'type' => 'do',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'action_discount',
                            'data' => ['target' => 'order', 'value' => 10.00],
                            'children' => [],
                        ],
                        [
                            'type' => 'action_discount',
                            'data' => ['target' => 'order', 'value' => 15.00],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $template = $this->createPromotionTemplate(PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setAstCache($ast);
        $promotion = $this->createPromotion('Multi Actions', $template);

        $strategy = new \App\Promotion\Strategy\FullReductionStrategy();
        $service = $this->createService([$strategy]);

        $service->apply($promotion, $context);

        self::assertSame(97500, $context->totalAmount);
    }

    public function testGetAvailableSortsByConfigPriorityRef(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;

        $template = $this->createPromotionTemplate();
        $template->setAstCache([
            'type' => 'program',
            'data' => ['priority' => ['value' => 'config.priority_value']],
            'children' => [],
        ]);

        $highPriority = $this->createPromotion('High', $template, '', true, null, null, ['priority_value' => 100]);
        $lowPriority = $this->createPromotion('Low', $template, '', true, null, null, ['priority_value' => 10]);

        $this->rep->method('findBy')->willReturn([$lowPriority, $highPriority]);

        $result = $this->createService()->getAvailable($context);
        self::assertSame('High', $result[0]->getName());
        self::assertSame('Low', $result[1]->getName());
    }
}
