<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Settlement\Contract\SettlementFunding;
use App\Settlement\Entity\SettlementAllocation;
use App\Settlement\Entity\SettlementPlan;
use App\Settlement\Entity\SettlementRule;
use App\Settlement\Entity\SettlementRuleVersion;
use App\Settlement\Integration\Fake\InMemorySettlementVoucherPort;
use App\Settlement\Repository\SettlementAllocationRepository;
use App\Settlement\Repository\SettlementPlanRepository;
use App\Settlement\Service\SettlementServiceInterface;
use App\Settlement\Service\SettlementRuleServiceInterface;
use App\Settlement\Service\SettlementRuleVersionServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class SettlementFlowIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private SettlementServiceInterface $service;
    private InMemorySettlementVoucherPort $voucherPort;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        $kernel = self::bootKernel();
        $container = $kernel->getContainer()->get('test.service_container');
        $this->em = $container->get('doctrine.orm.default_entity_manager');
        $this->service = $container->get(SettlementServiceInterface::class);
        $this->voucherPort = $container->get(InMemorySettlementVoucherPort::class);
    }

    public function testCreatePlanPostsAndReverses(): void
    {
        $this->publishSupplierRule();

        $funding = $this->funding('100.00');
        $plan = $this->service->createPlanFromFunding($funding);

        // Conservation: unallocated must be zero in both domains.
        self::assertSame(SettlementPlan::STATUS_PLANNED, $plan->getStatus());
        self::assertSame('0', $plan->getUnallocatedAmountQuantum());
        self::assertSame('0', $plan->getUnallocatedPostingAmount());
        self::assertCount(2, $plan->getAllocations());

        // === Post allocations ===
        /** @var SettlementAllocation $allocation */
        foreach ($plan->getAllocations() as $allocation) {
            $this->service->postAllocation($allocation->getUuid());
        }

        $this->em->refresh($plan);
        self::assertSame(SettlementPlan::STATUS_POSTED, $plan->getStatus());
        self::assertNotNull($plan->getRefundLockedAt());
        self::assertNull($plan->getRefundUnlockedAt());
        self::assertCount(2, $this->voucherPort->posts);

        // === Reverse all posted allocations ===
        foreach ($plan->getAllocations() as $allocation) {
            $this->service->reverseAllocation($allocation->getUuid(), 'rev-1', 'business_error', 'wrong recipient', 'admin');
        }

        $this->em->refresh($plan);
        self::assertSame(SettlementPlan::STATUS_REVERSED, $plan->getStatus());
        self::assertCount(2, $this->voucherPort->reversals);
    }

    public function testCreatePlanIsIdempotent(): void
    {
        $this->publishSupplierRule();

        $funding = $this->funding('50.00');
        $planA = $this->service->createPlanFromFunding($funding);
        $planB = $this->service->createPlanFromFunding($funding);

        self::assertSame($planA->getUuid(), $planB->getUuid());
        self::assertSame('0', $planB->getUnallocatedAmountQuantum());
    }

    public function testOrderItemRulesCreateAuditableAllocationsWithinOneOrderPlan(): void
    {
        $this->publishAgentItemRule();
        $plan = $this->service->createPlanFromFunding($this->funding('100.00', [
            'facts' => [
                'order.status' => 'paid',
                'order.agentRecipientType' => 'wallet',
                'order.agentRecipientId' => 'agent-wallet-a',
            ],
            'items' => [
                [
                    'id' => 'item-eligible-a',
                    'facts' => [
                        'item.specificationId' => 32,
                        'item.unitPrice' => '4.00',
                        'item.quantity' => 2,
                    ],
                    'snapshot' => ['specificationId' => 32, 'unitPrice' => '4.00', 'quantity' => 2],
                ],
                [
                    'id' => 'item-ineligible-price',
                    'facts' => [
                        'item.specificationId' => 32,
                        'item.unitPrice' => '3.00',
                        'item.quantity' => 4,
                    ],
                    'snapshot' => ['specificationId' => 32, 'unitPrice' => '3.00', 'quantity' => 4],
                ],
                [
                    'id' => 'item-eligible-b',
                    'facts' => [
                        'item.specificationId' => 32,
                        'item.unitPrice' => '5.00',
                        'item.quantity' => 3,
                    ],
                    'snapshot' => ['specificationId' => 32, 'unitPrice' => '5.00', 'quantity' => 3],
                ],
                [
                    'id' => 'item-ineligible-specification',
                    'facts' => [
                        'item.specificationId' => 33,
                        'item.unitPrice' => '5.00',
                        'item.quantity' => 5,
                    ],
                    'snapshot' => ['specificationId' => 33, 'unitPrice' => '5.00', 'quantity' => 5],
                ],
            ],
        ]));

        self::assertCount(3, $plan->getAllocations());
        $allocations = [];
        foreach ($plan->getAllocations() as $allocation) {
            $allocations[$allocation->getSourceItemId() ?? 'fallback'] = $allocation;
        }
        self::assertSame('agent-wallet-a', $allocations['item-eligible-a']->getRecipientId());
        self::assertSame('6000000000000000000', $allocations['item-eligible-a']->getExactAmountQuantum());
        self::assertSame(['specificationId' => 32, 'unitPrice' => '4.00', 'quantity' => 2], $allocations['item-eligible-a']->getSourceItemSnapshot());
        self::assertSame('agent-wallet-a', $allocations['item-eligible-b']->getRecipientId());
        self::assertSame('9000000000000000000', $allocations['item-eligible-b']->getExactAmountQuantum());
        self::assertSame('85000000000000000000', $allocations['fallback']->getExactAmountQuantum());
        self::assertNull($allocations['fallback']->getSourceItemId());
    }

    public function testPostingIsIdempotent(): void
    {
        $this->publishSupplierRule();
        $plan = $this->service->createPlanFromFunding($this->funding('30.00'));
        $allocation = $plan->getAllocations()->first();

        $this->service->postAllocation($allocation->getUuid());
        $this->em->clear();
        // Posting again (crash-retry) must not create a second voucher.
        $this->service->postAllocation($allocation->getUuid());

        self::assertCount(1, $this->voucherPort->posts);
    }

    public function testReversalWithInsufficientFundsStaysPendingAndKeepsRefundLocked(): void
    {
        $this->publishSupplierRule();
        $plan = $this->service->createPlanFromFunding($this->funding('30.00'));

        $allocation = $plan->getAllocations()->first();
        $this->service->postAllocation($allocation->getUuid());

        $this->voucherPort->failReversalWithInsufficientFunds = true;
        $this->service->reverseAllocation($allocation->getUuid(), 'rev-2', 'recall', 'spent funds', 'admin');
        $this->voucherPort->failReversalWithInsufficientFunds = false;

        $this->em->refresh($plan);
        self::assertSame(SettlementPlan::STATUS_REVERSAL_PENDING, $plan->getStatus());
        self::assertNotNull($plan->getRefundLockedAt());
        self::assertNull($plan->getRefundUnlockedAt());
    }

    public function testRuleServiceCreatesAndPublishesVersion(): void
    {
        /** @var SettlementRuleServiceInterface $ruleService */
        $ruleService = self::getContainer()->get(SettlementRuleServiceInterface::class);
        $rule = $ruleService->update($ruleService->new(), [
            'code' => 'managed-rule-' . uniqid(),
            'name' => 'Managed rule',
        ]);
        self::assertInstanceOf(SettlementRule::class, $rule);
        /** @var SettlementRuleVersionServiceInterface $ruleVersionService */
        $ruleVersionService = self::getContainer()->get(SettlementRuleVersionServiceInterface::class);
        $version = $ruleVersionService->update(
            $ruleVersionService->new(),
            [
                'definition' => [
                    'appliesTo' => ['manage.rule.v1'],
                    'recipient' => ['resolver' => 'literal', 'type' => 'platform', 'id' => 'default'],
                    'formula' => ['fundingAmount' => []],
                ],
                'ruleUuid' => $rule->getUuid(),
                'priority' => 100,
                'effectiveFrom' => new \DateTimeImmutable('-1 minute'),
                'effectiveTo' => null,
            ],
        );
        self::assertInstanceOf(SettlementRuleVersion::class, $version);

        $updated = $ruleVersionService->update($version, [
            'definition' => [
                'appliesTo' => ['manage.rule.v1'],
                'recipient' => ['resolver' => 'literal', 'type' => 'platform', 'id' => 'default'],
                'formula' => ['rateOf' => ['basis' => 'funding.distributable', 'bps' => 8000]],
            ],
            'priority' => 50,
            'effectiveFrom' => new \DateTimeImmutable('-1 minute'),
            'effectiveTo' => null,
        ]);
        self::assertInstanceOf(SettlementRuleVersion::class, $updated);

        $ruleService->publishVersion($rule, $version, 'admin');

        self::assertSame(SettlementRule::STATUS_PUBLISHED, $rule->getStatus());
        self::assertSame(1, $rule->getCurrentVersion());
        self::assertSame(SettlementRuleVersion::STATUS_PUBLISHED, $version->getStatus());
        self::assertSame(1, $version->getVersion());
        self::assertSame(50, $version->getPriority());
        self::assertSame(['rateOf' => ['basis' => 'funding.distributable', 'bps' => 8000]], $version->getDefinition()['formula']);
    }

    public function testRuleVersionCreationRejectsInvalidRuleUuid(): void
    {
        /** @var SettlementRuleVersionServiceInterface $ruleVersionService */
        $ruleVersionService = self::getContainer()->get(SettlementRuleVersionServiceInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ruleUuid must be a valid UUID.');
        $ruleVersionService->update($ruleVersionService->new(), [
            'ruleUuid' => 'not-a-uuid',
            'definition' => [
                'appliesTo' => ['manage.rule.v1'],
                'recipient' => ['resolver' => 'literal', 'type' => 'platform', 'id' => 'default'],
                'formula' => ['fundingAmount' => []],
            ],
            'priority' => 100,
            'effectiveFrom' => new \DateTimeImmutable(),
        ]);
    }

    private static int $ruleCounter = 0;

    private function publishSupplierRule(): void
    {
        self::$ruleCounter++;
        $code = 'supplier-share-' . self::$ruleCounter;
        $rule = new SettlementRule($code, 'Supplier share 80%');
        $rule->setStatus(SettlementRule::STATUS_PUBLISHED);
        $this->em->persist($rule);
        $this->em->flush();

        $definition = [
            'code' => $code,
            'appliesTo' => ['fixture.order.' . self::$ruleCounter . '.v1'],
            'conflictMode' => 'stack',
            'eligibility' => [
                'all' => [
                    'children' => [
                        ['factEquals' => ['order.status', 'paid']],
                    ],
                ],
            ],
            'recipient' => ['resolver' => 'context_candidate', 'version' => 1, 'key' => 'supplier'],
            'formula' => ['rateOf' => ['basis' => 'funding.distributable', 'bps' => 8000]],
            'reasonCode' => 'supplier',
        ];
        $version = new SettlementRuleVersion(
            $rule->getUuid(),
            1,
            $definition,
            hash('sha256', (string) json_encode($definition)),
            new \DateTimeImmutable('-1 day'),
            100,
        );
        $version->publish('admin');
        $this->em->persist($version);
        $this->em->flush();
    }

    private function publishAgentItemRule(): void
    {
        self::$ruleCounter++;
        $code = 'agent-item-share-' . self::$ruleCounter;
        $rule = new SettlementRule($code, 'Agent item share');
        $rule->setStatus(SettlementRule::STATUS_PUBLISHED);
        $this->em->persist($rule);
        $this->em->flush();

        $definition = [
            'code' => $code,
            'scope' => 'order_item',
            'appliesTo' => ['fixture.order.' . self::$ruleCounter . '.v1'],
            'eligibility' => [
                'all' => [
                    'children' => [
                        ['factEquals' => ['item.specificationId', 32]],
                        ['amountAtLeast' => ['item.unitPrice', '3.01']],
                    ],
                ],
            ],
            'recipient' => [
                'resolver' => 'fact_reference',
                'typeFact' => 'order.agentRecipientType',
                'idFact' => 'order.agentRecipientId',
            ],
            'formula' => [
                'multiplyByQuantity' => [
                    'value' => ['fixedAmount' => ['amount' => '3.00']],
                    'quantity' => 'item.quantity',
                ],
            ],
            'reasonCode' => 'agent_specification_reward',
        ];
        $version = new SettlementRuleVersion(
            $rule->getUuid(),
            1,
            $definition,
            hash('sha256', (string) json_encode($definition)),
            new \DateTimeImmutable('-1 day'),
            100,
        );
        $version->publish('admin');
        $this->em->persist($version);
        $this->em->flush();
    }

    /** @param array<string, mixed>|null $snapshot */
    private function funding(string $amount, ?array $snapshot = null): SettlementFunding
    {
        return new SettlementFunding(
            fundingId: 'funding-' . uniqid(),
            sourceType: 'fixture.order.' . self::$ruleCounter . '.v1',
            sourceId: 'order-' . uniqid(),
            confirmationReference: 'ref-' . uniqid(),
            currency: 'CNY',
            amountQuantum: \App\Settlement\Service\Money\QuantumAmount::fromDecimal($amount, 'CNY', 18)->quantum,
            calculationScale: 18,
            confirmedAt: new \DateTimeImmutable(),
            idempotencyKey: 'idem-' . uniqid(),
            snapshot: $snapshot ?? [
                'facts' => [
                    'order.status' => 'paid',
                    'order.id' => 'order-1',
                ],
                'recipientCandidates' => [
                    'supplier' => ['type' => 'merchant', 'id' => 'supplier-001'],
                ],
            ],
        );
    }
}
