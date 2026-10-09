<?php

declare(strict_types=1);

namespace App\Tests\Smoke\Settlement;

use App\Settlement\Contract\SettlementFunding;
use App\Settlement\Entity\SettlementAllocation;
use App\Settlement\Entity\SettlementRule;
use App\Settlement\Entity\SettlementRuleVersion;
use App\Settlement\Service\Money\QuantumAmount;
use App\Settlement\Service\SettlementRuleConfiguration;
use App\Settlement\Service\SettlementServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opt-in smoke test: run with
 * php vendor/bin/phpunit tests/Smoke/Settlement/NightclubSettlementFuzzySmokeTest.php
 */
final class NightclubSettlementFuzzySmokeTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private SettlementServiceInterface $settlement;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        $container = self::bootKernel()->getContainer()->get('test.service_container');
        $this->em = $container->get('doctrine.orm.default_entity_manager');
        $this->settlement = $container->get(SettlementServiceInterface::class);
    }

    public function testNightclubOrderItemSplitsConserveFundsAndLimitForwardingToOneHop(): void
    {
        mt_srand(20260819);
        $this->publishNightclubRules();

        $successes = 0;
        $configurationFailures = 0;
        $forwardedByFinalWallet = [];

        for ($scenario = 0; $scenario < 100; $scenario++) {
            if ($scenario % 20 === 0) {
                $this->assertInvalidNightclubConfigurationIsRejected($scenario);
                $configurationFailures++;
                continue;
            }
            [$funding, $items] = $this->nightclubFunding($scenario);
            $plan = $this->settlement->createPlanFromFunding($funding);
            $successes++;

            $allocated = BigInteger::zero();
            $fundingQuantum = BigInteger::of($funding->amountQuantum);
            /** @var SettlementAllocation $allocation */
            foreach ($plan->getAllocations() as $allocation) {
                $allocated = $allocated->plus($allocation->getExactAmountQuantum());
                $itemId = $allocation->getSourceItemId();
                if ($itemId === null || $allocation->getReasonCode() !== 'artist_share') {
                    continue;
                }

                $route = $items[$itemId]['artistRoute'];
                self::assertLessThanOrEqual(1, $route['hops']);
                self::assertSame($route['receivingWalletId'], $allocation->getRecipientId());
                $forwardedByFinalWallet[$route['finalWalletId']] = ($forwardedByFinalWallet[$route['finalWalletId']] ?? BigInteger::zero())
                    ->plus($allocation->getExactAmountQuantum());
            }

            self::assertTrue($allocated->isEqualTo($fundingQuantum), 'Every successful plan must conserve the order funding exactly.');
            self::assertSame('0', $plan->getUnallocatedAmountQuantum());
            self::assertSame('0', $plan->getUnallocatedPostingAmount());
        }

        self::assertSame(95, $successes);
        self::assertSame(5, $configurationFailures);
        self::assertNotEmpty($forwardedByFinalWallet);
        foreach ($forwardedByFinalWallet as $amount) {
            self::assertTrue($amount->isGreaterThan(BigInteger::zero()));
        }
    }

    private function publishNightclubRules(): void
    {
        $this->publishRule('nightclub-promoter', [
            'scope' => 'order_item',
            'appliesTo' => ['fixture.nightclub.v1'],
            'eligibility' => ['all' => ['children' => [
                ['factEquals' => ['item.specificationId', 32]],
                ['amountAtLeast' => ['item.unitPrice', '10.00']],
            ]]],
            'recipient' => ['resolver' => 'fact_reference', 'typeFact' => 'order.agentRecipientType', 'idFact' => 'order.agentRecipientId'],
            'formula' => ['multiplyByQuantity' => [
                'value' => ['fixedAmount' => ['amount' => '3.00']],
                'quantity' => 'item.quantity',
            ]],
            'reasonCode' => 'promoter_share',
        ]);
        $this->publishRule('nightclub-artist', [
            'scope' => 'order_item',
            'appliesTo' => ['fixture.nightclub.v1'],
            'eligibility' => ['factEquals' => ['item.hasArtist', true]],
            'recipient' => ['resolver' => 'fact_reference', 'typeFact' => 'item.artistRecipientType', 'idFact' => 'item.artistRecipientId'],
            'formula' => ['rateOf' => [
                'basis' => ['factAmount' => ['fact' => 'item.lineAmount']],
                'bps' => 2000,
            ]],
            'reasonCode' => 'artist_share',
        ]);
        $this->publishRule('nightclub-floor-manager', [
            'scope' => 'order_item',
            'appliesTo' => ['fixture.nightclub.v1'],
            'eligibility' => ['factEquals' => ['item.vip', true]],
            'recipient' => ['resolver' => 'fact_reference', 'typeFact' => 'item.floorRecipientType', 'idFact' => 'item.floorRecipientId'],
            'formula' => ['rateOf' => [
                'basis' => ['factAmount' => ['fact' => 'item.lineAmount']],
                'bps' => 500,
            ]],
            'reasonCode' => 'floor_manager_share',
        ]);
        $this->publishRule('nightclub-bartender', [
            'scope' => 'order_item',
            'appliesTo' => ['fixture.nightclub.v1'],
            'eligibility' => ['factEquals' => ['item.hasBartender', true]],
            'recipient' => ['resolver' => 'context_candidate', 'key' => 'item.bartender.wallet'],
            'formula' => ['multiplyByQuantity' => [
                'value' => ['fixedAmount' => ['amount' => '1.00']],
                'quantity' => 'item.quantity',
            ]],
            'reasonCode' => 'bartender_share',
        ]);
    }

    /**
     * @return array{0: SettlementFunding, 1: array<string, array{artistRoute: array{hops: int, receivingWalletId: string, finalWalletId: string}>}
     */
    private function nightclubFunding(int $scenario): array
    {
        $items = [];
        $itemRoutes = [];
        $orderAmountMinor = 0;
        $itemCount = mt_rand(3, 7);
        for ($index = 0; $index < $itemCount; $index++) {
            $id = "night-$scenario-item-$index";
            $quantity = mt_rand(1, 5);
            $unitPriceMinor = mt_rand(1000, 8000);
            $lineAmountMinor = $unitPriceMinor * $quantity;
            $orderAmountMinor += $lineAmountMinor;
            $hasArtist = $index === 0 || mt_rand(0, 1) === 1;
            $forwarded = $hasArtist && mt_rand(0, 3) === 0;
            $artistWallet = 'artist-wallet-' . mt_rand(1, 25);
            $receivingWallet = $forwarded ? 'artist-forwarder-wallet-' . mt_rand(1, 8) : $artistWallet;
            $route = [
                'hops' => $forwarded ? 1 : 0,
                'receivingWalletId' => $receivingWallet,
                'finalWalletId' => $artistWallet,
            ];
            $facts = [
                'item.specificationId' => mt_rand(0, 4) === 0 ? 33 : 32,
                'item.unitPrice' => $this->decimalFromMinor($unitPriceMinor),
                'item.quantity' => $quantity,
                'item.lineAmount' => $this->decimalFromMinor($lineAmountMinor),
                'item.hasArtist' => $hasArtist,
                'item.hasBartender' => mt_rand(0, 1) === 1,
                'item.vip' => mt_rand(0, 3) === 0,
                'item.artistRecipientType' => 'wallet',
                'item.artistRecipientId' => $receivingWallet,
                'item.floorRecipientType' => 'wallet',
                'item.floorRecipientId' => 'floor-wallet-' . mt_rand(1, 5),
            ];
            $items[] = [
                'id' => $id,
                'facts' => $facts,
                'recipientCandidates' => [
                    'item.bartender.wallet' => ['type' => 'wallet', 'id' => 'bartender-wallet-' . mt_rand(1, 20)],
                ],
                'snapshot' => [
                    'specificationId' => $facts['item.specificationId'],
                    'unitPrice' => $facts['item.unitPrice'],
                    'quantity' => $quantity,
                    'lineAmount' => $facts['item.lineAmount'],
                    'artistRoute' => $route,
                ],
            ];
            $itemRoutes[$id] = ['artistRoute' => $route];
        }

        $amount = $this->decimalFromMinor($orderAmountMinor);
        return [
            new SettlementFunding(
                fundingId: "nightclub-funding-$scenario",
                sourceType: 'fixture.nightclub.v1',
                sourceId: "nightclub-order-$scenario",
                confirmationReference: "nightclub-payment-$scenario",
                currency: 'CNY',
                amountQuantum: QuantumAmount::fromDecimal($amount, 'CNY', 18)->quantum,
                calculationScale: 18,
                confirmedAt: new \DateTimeImmutable(),
                idempotencyKey: "nightclub-idempotency-$scenario",
                snapshot: [
                    'facts' => [
                        'order.status' => 'paid',
                        'order.agentRecipientType' => 'wallet',
                        'order.agentRecipientId' => 'promoter-wallet-' . mt_rand(1, 20),
                    ],
                    'items' => $items,
                ],
            ),
            $itemRoutes,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function publishRule(string $code, array $definition): void
    {
        $rule = new SettlementRule($code, $code);
        $rule->setStatus(SettlementRule::STATUS_PUBLISHED);
        $this->em->persist($rule);
        $this->em->flush();
        $version = new SettlementRuleVersion(
            $rule->getUuid(),
            1,
            $definition,
            hash('sha256', (string) json_encode($definition)),
            new \DateTimeImmutable('-1 day'),
            100,
        );
        $version->publish('smoke-test');
        $this->em->persist($version);
        $this->em->flush();
    }

    private function assertInvalidNightclubConfigurationIsRejected(int $scenario): void
    {
        $definition = [
            'scope' => $scenario === 0 ? 'nightclub_line' : 'order_item',
            'appliesTo' => ['fixture.nightclub.v1'],
            'recipient' => ['resolver' => 'literal', 'type' => 'wallet', 'id' => 'nightclub-wallet'],
            'formula' => ['fundingAmount' => []],
        ];
        if ($scenario === 20) {
            $definition['recipient'] = ['resolver' => 'unknown'];
        }
        if ($scenario === 40) {
            $definition['formula'] = ['rateOf' => ['basis' => 'funding.distributable', 'bps' => 10001]];
        }
        if ($scenario === 60) {
            $definition['eligibility'] = ['unknownPredicate' => []];
        }
        if ($scenario === 80) {
            $definition['formula'] = ['fixedAmount' => ['amount' => '']];
        }

        try {
            (new SettlementRuleConfiguration())->validate($definition);
            self::fail('Invalid nightclub configuration must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function decimalFromMinor(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
