<?php

declare(strict_types=1);

namespace App\Tests\Integration\Promotion;

use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Trade\Service\OrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PromotionPricingPipelineIntegrationTest extends KernelTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        // The test database is shared by integration classes. Promotions are
        // intentionally disabled before each scenario to prevent prior global
        // campaigns from altering a later quotation.
        $em->getConnection()->executeStatement('UPDATE promotion SET enabled = false');
        $em->clear();
    }

    public function testQuoteUsesOnlyTheCurrentStorePromotionOnce(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $specification = $this->createSpecification($em, 'Pipeline product', 15000);

        $storeAPromotion = $this->createFullReductionPromotion($em, 'pipeline-store-a', 'store-a', 50);
        $this->createFullReductionPromotion($em, 'pipeline-store-b', 'store-b', 100);
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $result = $service->calculatePrices([
            ['specificationId' => $specification->getId(), 'quantity' => 2],
        ], 'CNY', 'store-a');

        self::assertSame(30000, $result->items[0]['price'], 'Line subtotal remains auditable.');
        self::assertSame(25000, $result->totalAmount, '300.00 - 50.00 promotion = 250.00.');
        self::assertCount(1, $result->meta['promotion']['inner']);
        self::assertSame($storeAPromotion->getId(), $result->meta['promotion']['inner'][0]['promotionId']);
        self::assertSame(0, $result->meta['promotion']['inner'][0]['iteration']);
    }

    public function testQuoteWithoutStoreDoesNotApplyStoreScopedPromotions(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $specification = $this->createSpecification($em, 'No store product', 15000);
        $this->createFullReductionPromotion($em, 'no-store-campaign', 'store-a', 50);
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $result = $service->calculatePrices([
            ['specificationId' => $specification->getId(), 'quantity' => 2],
        ]);

        self::assertSame(30000, $result->totalAmount);
        self::assertSame([], $result->meta['promotion']['inner']);
    }

    public function testGlobalPromotionAppliesWithAndWithoutStoreCode(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $specification = $this->createSpecification($em, 'Global promotion product', 15000);
        $globalPromotion = $this->createFullReductionPromotion($em, 'global-campaign', null, 50);
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $items = [['specificationId' => $specification->getId(), 'quantity' => 2]];

        $globalQuote = $service->calculatePrices($items);
        $storeQuote = $service->calculatePrices($items, 'CNY', 'store-without-local-campaign');

        self::assertSame(25000, $globalQuote->totalAmount);
        self::assertSame(25000, $storeQuote->totalAmount);
        self::assertSame($globalPromotion->getId(), $globalQuote->meta['promotion']['inner'][0]['promotionId']);
        self::assertSame($globalPromotion->getId(), $storeQuote->meta['promotion']['inner'][0]['promotionId']);

        // The shared test schema stays alive across cases; prevent this global
        // campaign from intentionally affecting later, isolated scenarios.
        $globalPromotion->setEnabled(false);
        $em->flush();
    }

    public function testTargetedMemberItemDiscountStacksWithOrderDiscount(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $member = $this->createUser($em, 'member-stack');
        $targetSpecification = $this->createSpecification($em, 'Target item', 10000);
        $regularSpecification = $this->createSpecification($em, 'Regular item', 15000);

        $this->createDiscountPromotion(
            $em,
            'member-item-90',
            'member-stack-store',
            PromotionTemplate::PHASE_INNER,
            ['target' => 'items', 'rate' => 90],
            ['user.id' => $member->getId()],
            ['specification_ids' => [$targetSpecification->getId()], 'rate' => 90],
        );
        $this->createDiscountPromotion(
            $em,
            'order-80',
            'member-stack-store',
            PromotionTemplate::PHASE_OUTER,
            ['target' => 'order', 'value' => 80],
            ['cart.subtotal' => 20000],
        );
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $this->setServiceUser($service, $member);
        $result = $service->calculatePrices([
            ['specificationId' => $targetSpecification->getId(), 'quantity' => 1],
            ['specificationId' => $regularSpecification->getId(), 'quantity' => 1],
        ], 'CNY', 'member-stack-store');

        self::assertSame(9000, $result->items[0]['price']);
        self::assertSame(15000, $result->items[1]['price']);
        self::assertSame(19200, $result->totalAmount, '250.00 -> 240.00 -> 192.00');
        self::assertCount(1, $result->meta['promotion']['inner']);
        self::assertArrayHasKey('outer', $result->meta['promotion']);
    }

    public function testBestPriceAppliesOnlyTheCheapestCandidate(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $member = $this->createUser($em, 'member-best-price');
        $targetSpecification = $this->createSpecification($em, 'Best price target', 10000);
        $regularSpecification = $this->createSpecification($em, 'Best price regular', 15000);

        $this->createDiscountPromotion(
            $em,
            'best-member-item-90',
            'best-price-store',
            PromotionTemplate::PHASE_INNER,
            ['target' => 'items', 'rate' => 90],
            ['user.id' => $member->getId()],
            ['specification_ids' => [$targetSpecification->getId()], 'rate' => 90],
            Promotion::CONFLICT_BEST_PRICE,
        );
        $orderPromotion = $this->createDiscountPromotion(
            $em,
            'best-order-80',
            'best-price-store',
            PromotionTemplate::PHASE_OUTER,
            ['target' => 'order', 'value' => 80],
            ['cart.subtotal' => 20000],
            null,
            Promotion::CONFLICT_BEST_PRICE,
        );
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $this->setServiceUser($service, $member);
        $result = $service->calculatePrices([
            ['specificationId' => $targetSpecification->getId(), 'quantity' => 1],
            ['specificationId' => $regularSpecification->getId(), 'quantity' => 1],
        ], 'CNY', 'best-price-store');

        self::assertSame(10000, $result->items[0]['price'], 'The item discount was simulated but not applied.');
        self::assertSame(15000, $result->items[1]['price']);
        self::assertSame(20000, $result->totalAmount, 'The 80% order candidate beats the 9-discount item candidate.');
        self::assertSame($orderPromotion->getId(), $result->meta['promotion']['bestPrice']['promotionId']);
        self::assertSame([], $result->meta['promotion']['inner']);
        self::assertArrayNotHasKey('outer', $result->meta['promotion']);
    }

    public function testMultiSkuMemberCampaignStacksWithOrderCampaign(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $member = $this->createUser($em, 'complex-cart-member');
        $laptop = $this->createSpecification($em, 'Laptop', 129900);
        $mouse = $this->createSpecification($em, 'Mouse', 9900);
        $cable = $this->createSpecification($em, 'Cable', 2900);

        $this->createDiscountPromotion(
            $em,
            'member-electronics-90',
            'complex-cart-store',
            PromotionTemplate::PHASE_INNER,
            ['target' => 'items', 'rate' => 90],
            ['user.id' => $member->getId()],
            ['specification_ids' => [$laptop->getId(), $cable->getId()], 'rate' => 90],
        );
        $this->createDiscountPromotion(
            $em,
            'complex-cart-order-95',
            'complex-cart-store',
            PromotionTemplate::PHASE_OUTER,
            ['target' => 'order', 'value' => 95],
            ['cart.subtotal' => 100000],
        );
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $this->setServiceUser($service, $member);
        $result = $service->calculatePrices([
            ['specificationId' => $laptop->getId(), 'quantity' => 1],
            ['specificationId' => $mouse->getId(), 'quantity' => 2],
            ['specificationId' => $cable->getId(), 'quantity' => 3],
        ], 'CNY', 'complex-cart-store');

        // 1,584.00 subtotal -> selected items 9折 => 1,445.40 -> order 95折 => 1,373.13.
        self::assertSame(116910, $result->items[0]['price']);
        self::assertSame(19800, $result->items[1]['price']);
        self::assertSame(7830, $result->items[2]['price']);
        self::assertSame(137313, $result->totalAmount);
        self::assertCount(1, $result->meta['promotion']['inner']);
        self::assertArrayHasKey('outer', $result->meta['promotion']);
    }

    public function testExpiredCampaignIsIgnoredWhileActiveCampaignRemainsApplicable(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $main = $this->createSpecification($em, 'Timed main item', 20000);
        $accessory = $this->createSpecification($em, 'Timed accessory', 5000);

        $expiredPromotion = $this->createDiscountPromotion(
            $em,
            'expired-order-50',
            'timed-store',
            PromotionTemplate::PHASE_OUTER,
            ['target' => 'order', 'value' => 50],
            ['cart.subtotal' => 20000],
        );
        $expiredPromotion->setEndTime(new \DateTimeImmutable('-1 minute'));
        $activePromotion = $this->createDiscountPromotion(
            $em,
            'active-order-90',
            'timed-store',
            PromotionTemplate::PHASE_OUTER,
            ['target' => 'order', 'value' => 90],
            ['cart.subtotal' => 20000],
        );
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $result = $service->calculatePrices([
            ['specificationId' => $main->getId(), 'quantity' => 1],
            ['specificationId' => $accessory->getId(), 'quantity' => 1],
        ], 'CNY', 'timed-store');

        self::assertSame(22500, $result->totalAmount);
        self::assertSame($activePromotion->getId(), $result->meta['promotion']['outer']['promotionId']);
        self::assertNotSame($expiredPromotion->getId(), $result->meta['promotion']['outer']['promotionId']);
    }

    public function testMixedRulesProduceAnAuditableLowestPriceQuote(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $member = $this->createUser($em, 'mixed-rules-member');
        $featured = $this->createSpecification($em, 'Mixed featured item', 10000);
        $accessory = $this->createSpecification($em, 'Mixed accessory', 5000);
        $consumable = $this->createSpecification($em, 'Mixed consumable', 2000);

        // Global: spend 200.00, save 20.00.
        $this->createFullReductionPromotion($em, 'mixed-global-reduction', null, 20);
        // Member-specific: featured SKU gets 9折.
        $this->createDiscountPromotion(
            $em,
            'mixed-member-featured-90',
            'mixed-store',
            PromotionTemplate::PHASE_INNER,
            ['target' => 'items', 'rate' => 90],
            ['user.id' => $member->getId()],
            ['specification_ids' => [$featured->getId()], 'rate' => 90],
        );
        // Every third featured unit is 5折.
        $this->createNthDiscountPromotion($em, 'mixed-third-featured-50', 'mixed-store', 3, 50);
        // Standard order-level 95折 applies before best-price candidates are compared.
        $this->createDiscountPromotion(
            $em,
            'mixed-order-95',
            'mixed-store',
            PromotionTemplate::PHASE_OUTER,
            ['target' => 'order', 'value' => 95],
            ['cart.subtotal' => 20000],
        );
        // best_price: 50% off accessory versus 85% off the whole order.
        $bestAccessory = $this->createDiscountPromotion(
            $em,
            'mixed-best-accessory-50',
            'mixed-store',
            PromotionTemplate::PHASE_INNER,
            ['target' => 'items', 'rate' => 50],
            [],
            ['specification_ids' => [$accessory->getId()], 'rate' => 50],
            Promotion::CONFLICT_BEST_PRICE,
        );
        $this->createDiscountPromotion(
            $em,
            'mixed-best-order-85',
            'mixed-store',
            PromotionTemplate::PHASE_OUTER,
            ['target' => 'order', 'value' => 85],
            ['cart.subtotal' => 20000],
            null,
            Promotion::CONFLICT_BEST_PRICE,
        );
        $em->flush();

        $service = static::getContainer()->get(OrderServiceInterface::class);
        $this->setServiceUser($service, $member);
        $result = $service->calculatePrices([
            ['specificationId' => $featured->getId(), 'quantity' => 3],
            ['specificationId' => $accessory->getId(), 'quantity' => 2],
            ['specificationId' => $consumable->getId(), 'quantity' => 1],
        ], 'CNY', 'mixed-store');

        // 420.00 - 20.00 - 30.00 - 50.00 = 320.00; 95折 = 304.00.
        // best_price compares 85折 => 258.40 with accessory 5折 => 254.00.
        self::assertSame($bestAccessory->getId(), $result->meta['promotion']['bestPrice']['promotionId']);
        self::assertSame(22000, $result->items[0]['price']);
        self::assertSame(5000, $result->items[1]['price']);
        self::assertSame(2000, $result->items[2]['price']);
        self::assertSame(25400, $result->totalAmount);
        self::assertCount(3, $result->meta['promotion']['inner']);
        self::assertArrayHasKey('outer', $result->meta['promotion']);
    }

    private function createSpecification(EntityManagerInterface $em, string $name, int $price): Specification
    {
        $name = $this->uniqueName($name);
        $product = (new Product())->setName($name);
        $specification = (new Specification())
            ->setProduct($product)
            ->setName($name . ' specification')
            ->setPrice($price);

        $em->persist($product);
        $em->persist($specification);
        $em->flush();

        return $specification;
    }

    private function createFullReductionPromotion(EntityManagerInterface $em, string $name, ?string $storeCode, int $amount): Promotion
    {
        $name = $this->uniqueName($name);
        $template = (new PromotionTemplate())
            ->setName($name . ' template')
            ->setType(PromotionTemplate::TYPE_FULL_REDUCTION)
            ->setPhase(PromotionTemplate::PHASE_INNER)
            ->setEnabled(true)
            ->setDsl('type: full_reduction')
            ->setAstCache([
                'type' => 'program',
                'data' => ['type' => 'full_reduction'],
                'children' => [
                    [
                        'type' => 'when',
                        'data' => [],
                        'children' => [[
                            'type' => 'condition',
                            'data' => [
                                'op' => '>=',
                                'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                'right' => ['type' => 'literal', 'data' => ['value' => 20000], 'children' => []],
                            ],
                            'children' => [],
                        ]],
                    ],
                    [
                        'type' => 'do',
                        'data' => [],
                        'children' => [[
                            'type' => 'action_discount',
                            'data' => ['target' => 'order', 'value' => $amount],
                            'children' => [],
                        ]],
                    ],
                ],
            ]);

        $promotion = (new Promotion())
            ->setName($name)
            ->setTemplate($template)
            ->setEnabled(true);

        if ($storeCode !== null) {
            $promotion->setStoreCode($storeCode);
        }

        $em->persist($template);
        $em->persist($promotion);

        return $promotion;
    }

    private function createDiscountPromotion(
        EntityManagerInterface $em,
        string $name,
        string $storeCode,
        int $phase,
        array $action,
        array $conditions,
        ?array $config = null,
        string $conflictMode = Promotion::CONFLICT_STACKABLE,
    ): Promotion {
        $name = $this->uniqueName($name);
        $children = [];
        if ($conditions !== []) {
            $conditionNodes = [];
            foreach ($conditions as $path => $value) {
                $conditionNodes[] = [
                    'type' => 'condition',
                    'data' => [
                        'op' => $path === 'user.id' ? '==' : '>=',
                        'left' => ['type' => 'path', 'data' => ['value' => $path], 'children' => []],
                        'right' => is_string($value) && str_starts_with($value, 'config.')
                            ? ['type' => 'path', 'data' => ['value' => $value], 'children' => []]
                            : ['type' => 'literal', 'data' => ['value' => $value], 'children' => []],
                    ],
                    'children' => [],
                ];
            }
            $children[] = ['type' => 'when', 'data' => [], 'children' => $conditionNodes];
        }
        $children[] = [
            'type' => 'do',
            'data' => [],
            'children' => [['type' => 'action_discount', 'data' => $action, 'children' => []]],
        ];

        $template = (new PromotionTemplate())
            ->setName($name . ' template')
            ->setType(PromotionTemplate::TYPE_DISCOUNT)
            ->setPhase($phase)
            ->setEnabled(true)
            ->setDsl('type: discount')
            ->setAstCache(['type' => 'program', 'data' => ['type' => 'discount'], 'children' => $children]);

        $promotion = (new Promotion())
            ->setName($name)
            ->setTemplate($template)
            ->setStoreCode($storeCode)
            ->setConfig($config)
            ->setConflictMode($conflictMode)
            ->setEnabled(true);

        $em->persist($template);
        $em->persist($promotion);

        return $promotion;
    }

    private function createNthDiscountPromotion(EntityManagerInterface $em, string $name, string $storeCode, int $position, int $rate): Promotion
    {
        $name = $this->uniqueName($name);
        $template = (new PromotionTemplate())
            ->setName($name . ' template')
            ->setType(PromotionTemplate::TYPE_NTH_DISCOUNT)
            ->setPhase(PromotionTemplate::PHASE_INNER)
            ->setEnabled(true)
            ->setDsl('type: nth_discount')
            ->setAstCache([
                'type' => 'program',
                'data' => ['type' => 'nth_discount'],
                'children' => [[
                    'type' => 'do',
                    'data' => [],
                    'children' => [[
                        'type' => 'action_discount',
                        'data' => ['target' => 'item', 'position' => $position, 'rate' => $rate],
                        'children' => [],
                    ]],
                ]],
            ]);

        $promotion = (new Promotion())
            ->setName($name)
            ->setTemplate($template)
            ->setStoreCode($storeCode)
            ->setEnabled(true);

        $em->persist($template);
        $em->persist($promotion);

        return $promotion;
    }

    private function createUser(EntityManagerInterface $em, string $name): User
    {
        $name = $this->uniqueName($name);
        $user = (new User())
            ->setEmail($name . '@example.test')
            ->setUsername($name)
            ->setPassword('test-password');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function setServiceUser(OrderServiceInterface $service, User $user): void
    {
        $property = new \ReflectionProperty(\App\Core\Service\BaseService::class, 'user');
        $property->setValue($service, $user);
    }

    private function uniqueName(string $name): string
    {
        return $name . '-' . bin2hex(random_bytes(4));
    }
}
