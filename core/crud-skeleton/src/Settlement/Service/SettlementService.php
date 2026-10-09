<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Settlement\Contract\ComputedAllocation;
use App\Settlement\Contract\ConfirmedAllocation;
use App\Settlement\Contract\PostedAllocation;
use App\Settlement\Contract\RecipientReference;
use App\Settlement\Contract\ReversalRequest;
use App\Settlement\Contract\SettlementFunding;
use App\Settlement\Contract\SettlementSubject;
use App\Settlement\Entity\SettlementAllocation;
use App\Settlement\Entity\SettlementPlan;
use App\Settlement\Exception\SettlementException;
use App\Settlement\Exception\SettlementVoucherException;
use App\Settlement\Port\ClockInterface;
use App\Settlement\Port\SettlementVoucherPort;
use App\Settlement\Repository\SettlementAllocationRepository;
use App\Settlement\Repository\SettlementPlanRepository;
use App\Settlement\Repository\SettlementRuleVersionRepository;
use App\Settlement\Context\SettlementContextResolverRegistry;
use App\Settlement\Service\Money\QuantumAmount;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;

class SettlementService implements SettlementServiceInterface
{
    public const DEFAULT_POSTING_SCALE = 2;
    public const FALLBACK_TYPE = 'platform';
    public const FALLBACK_ID = 'default';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettlementPlanRepository $planRepository,
        private readonly SettlementAllocationRepository $allocationRepository,
        private readonly SettlementRuleVersionRepository $ruleVersionRepository,
        private readonly SettlementContextResolverRegistry $contextResolvers,
        private readonly SettlementRuleEngine $ruleEngine,
        private readonly SettlementOutboxService $outbox,
        private readonly SettlementVoucherPort $voucherPort,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createPlanFromFunding(SettlementFunding $funding): SettlementPlan
    {
        $existing = $this->planRepository->findBySource($funding->sourceType, $funding->sourceId, $funding->fundingKind);
        if ($existing !== null) {
            $this->assertFingerprint($existing, $funding);
            return $existing;
        }

        $this->em->wrapInTransaction(function () use ($funding) {
            // Re-check under the transaction to narrow the race window.
            $existing = $this->planRepository->findBySource($funding->sourceType, $funding->sourceId, $funding->fundingKind);
            if ($existing !== null) {
                $this->assertFingerprint($existing, $funding);
                return;
            }

            $now = $this->clock->now();
            $subject = new SettlementSubject(strtolower($funding->sourceType), $funding->sourceId, 'v1');
            $resolver = $this->contextResolvers->get($subject);
            $context = $resolver->resolve($funding, $subject, $now);

            $versions = $this->ruleVersionRepository->findActiveAt($now);
            $computed = $this->ruleEngine->evaluate(
                $context,
                $versions,
                self::DEFAULT_POSTING_SCALE,
                $funding->amountQuantum,
                self::FALLBACK_TYPE,
                self::FALLBACK_ID,
            );

            $plan = $this->buildPlan($funding, $context, $computed, $now);
            $this->em->persist($plan);

            $built = [];
            $sequence = 1;
            foreach ($computed as $allocation) {
                $allocationEntity = $this->buildAllocation($plan, $allocation, $sequence);
                $plan->getAllocations()->add($allocationEntity);
                $built[] = [$allocationEntity, $allocation];
                $sequence++;
            }

            // Totals
            $allocated = $this->sumQuantum($computed);
            $exactTotal = BigInteger::of($allocated);
            $unallocatedQ = BigInteger::of($funding->amountQuantum)->minus($exactTotal);
            $postingTotal = BigInteger::zero();
            foreach ($computed as $c) {
                $postingTotal = $postingTotal->plus(BigInteger::of($c->postingAmount));
            }
            $fundingPosting = BigInteger::of($funding->amountQuantum)
                ->quotient(BigInteger::of(10)->power(max(0, $funding->calculationScale - self::DEFAULT_POSTING_SCALE)));
            $unallocatedPosting = $fundingPosting->minus($postingTotal);

            $plan->setTotals(
                $allocated,
                $unallocatedQ->toBase(10),
                $postingTotal->toBase(10),
                $unallocatedPosting->toBase(10),
            );
            $plan->setFallbackRecipient(self::FALLBACK_TYPE, self::FALLBACK_ID);
            $plan->markPlanned();

            // Emit posting commands within the same transaction as the plan.
            $this->emitPostingCommands($plan, $built);
        });

        $fresh = $this->planRepository->findBySource($funding->sourceType, $funding->sourceId, $funding->fundingKind);
        if ($fresh === null) {
            throw new SettlementException('Settlement plan could not be loaded after creation');
        }
        return $fresh;
    }

    public function postAllocation(string $allocationUuid): void
    {
        $allocation = $this->allocationRepository->findByUuid($allocationUuid);
        if ($allocation === null) {
            throw new SettlementException("Allocation not found: $allocationUuid");
        }
        if ($allocation->getStatus() === SettlementAllocation::STATUS_POSTED) {
            return;
        }
        if (in_array($allocation->getStatus(), [SettlementAllocation::STATUS_CANCELLED, SettlementAllocation::STATUS_REVERSED], true)) {
            return;
        }
        if ($allocation->getStatus() !== SettlementAllocation::STATUS_POSTING_REQUESTED) {
            $allocation->markPostingRequested();
            $this->em->persist($allocation);
            // Make the idempotency claim durable before invoking the Wallet boundary.
            $this->em->flush();
        }

        $confirmed = new ConfirmedAllocation(
            allocationUuid: $allocation->getUuid(),
            planUuid: $allocation->getPlanUuid(),
            recipient: new RecipientReference($allocation->getRecipientType(), $allocation->getRecipientId()),
            currency: $allocation->getPlan()->getCurrency(),
            postingScale: $allocation->getPostingScale(),
            postingAmount: QuantumAmount::of($allocation->getPostingAmount(), $allocation->getPlan()->getCurrency(), $allocation->getPostingScale()),
            postingIdempotencyKey: $allocation->getPostingIdempotencyKey(),
            reasonCode: $allocation->getReasonCode(),
        );

        try {
            $receipt = $this->voucherPort->post($confirmed);
            $this->assertReceipt($receipt, $allocation->getPostingIdempotencyKey(), 'applied');
            $allocation->markPosted($receipt->externalReference);
        } catch (SettlementVoucherException $e) {
            if ($e->retryable) {
                $allocation->markRetryableFailure('voucher_retryable', $e->getMessage(), $this->clock->now()->modify('+60 seconds'));
            } else {
                $allocation->markFailed($e->classification ?? 'voucher_rejected', $e->getMessage());
            }
        }
        $this->em->persist($allocation);
        $this->em->flush();
        $this->reconcilePlanState($allocation->getPlan());
        $this->em->flush();
    }

    public function reverseAllocation(string $allocationUuid, string $reversalUuid, string $reasonCode, string $reasonDetail, string $requestedBy): void
    {
        $allocation = $this->allocationRepository->findByUuid($allocationUuid);
        if ($allocation === null) {
            throw new SettlementException("Allocation not found: $allocationUuid");
        }

        if ($allocation->getStatus() === SettlementAllocation::STATUS_PLANNED
            || $allocation->getStatus() === SettlementAllocation::STATUS_POSTING_REQUESTED
            || $allocation->getStatus() === SettlementAllocation::STATUS_RETRYABLE_FAILURE
        ) {
            $allocation->cancel();
            $this->em->persist($allocation);
            $this->em->flush();
            $this->reconcilePlanState($allocation->getPlan());
            $this->em->flush();
            return;
        }

        if (!in_array($allocation->getStatus(), [SettlementAllocation::STATUS_POSTED, SettlementAllocation::STATUS_REVERSAL_PENDING], true)) {
            return;
        }

        if ($allocation->getStatus() === SettlementAllocation::STATUS_POSTED) {
            $allocation->markReversalRequested();
            $this->em->persist($allocation);
            // Persist the original-voucher reversal claim before the Wallet call.
            $this->em->flush();
        }

        $posted = new PostedAllocation(
            allocationUuid: $allocation->getUuid(),
            planUuid: $allocation->getPlanUuid(),
            recipient: new RecipientReference($allocation->getRecipientType(), $allocation->getRecipientId()),
            currency: $allocation->getPlan()->getCurrency(),
            postingScale: $allocation->getPostingScale(),
            postingAmount: $allocation->getPostingAmount(),
            postingIdempotencyKey: $allocation->getPostingIdempotencyKey(),
            externalReference: (string) $allocation->getPostingReference(),
            reversalIdempotencyKey: 'settlement-reversal:' . $allocation->getUuid(),
        );
        $request = new ReversalRequest($reversalUuid, $reasonCode, $reasonDetail, $requestedBy);

        try {
            $receipt = $this->voucherPort->reverse($posted, $request);
            $this->assertReceipt($receipt, $posted->reversalIdempotencyKey, 'reversed');
            $allocation->markReversed($posted->reversalIdempotencyKey, $receipt->externalReference);
        } catch (SettlementVoucherException $e) {
            if ($e->classification === 'insufficient_funds') {
                $allocation->markReversalPending($e->getMessage());
            } else {
                $allocation->markFailed($e->classification ?? 'voucher_reversal_rejected', $e->getMessage());
            }
        }
        $this->em->persist($allocation);
        $this->em->flush();
        $this->reconcilePlanState($allocation->getPlan());
        $this->em->flush();
    }

    /**
     * @param list<ComputedAllocation> $computed
     */
    private function buildPlan(SettlementFunding $funding, \App\Settlement\Contract\SettlementContext $context, array $computed, \DateTimeImmutable $now): SettlementPlan
    {
        $fundingPosting = QuantumAmount::of($funding->amountQuantum, $funding->currency, $funding->calculationScale)
            ->toPostingMinor(self::DEFAULT_POSTING_SCALE);

        $contextSnapshot = [
            'facts' => $context->facts,
            'recipientCandidates' => array_map(
                static fn (RecipientReference $recipient): array => ['type' => $recipient->type, 'id' => $recipient->id],
                $context->recipientCandidates,
            ),
            'items' => array_map(
                static fn (\App\Settlement\Contract\SettlementItemContext $item): array => [
                    'id' => $item->id,
                    'facts' => $item->facts,
                    'recipientCandidates' => array_map(
                        static fn (RecipientReference $recipient): array => ['type' => $recipient->type, 'id' => $recipient->id],
                        $item->recipientCandidates,
                    ),
                    'snapshot' => $item->snapshot,
                ],
                $context->items,
            ),
        ];
        $ruleSnapshot = [];
        $calculationTrace = [];
        foreach ($computed as $allocation) {
            $ruleSnapshot[] = [
                'code' => $allocation->ruleCode,
                'versionUuid' => $allocation->ruleVersionUuid,
            ];
            $calculationTrace[] = [
                'allocationKey' => $allocation->allocationKey,
                'exactAmountQuantum' => $allocation->exactAmountQuantum,
                'postingAmount' => $allocation->postingAmount,
                'roundingDeltaQuantum' => $allocation->roundingDeltaQuantum,
                'evidence' => $allocation->evidence,
            ];
        }

        return new SettlementPlan(
            fundingId: $funding->fundingId,
            sourceType: $funding->sourceType,
            sourceId: $funding->sourceId,
            fundingKind: $funding->fundingKind,
            confirmationReference: $funding->confirmationReference,
            fundingFingerprint: $this->fingerprint($funding),
            currency: $funding->currency,
            calculationScale: $funding->calculationScale,
            fundingAmountQuantum: $funding->amountQuantum,
            postingScale: self::DEFAULT_POSTING_SCALE,
            fundingPostingAmount: (string) $fundingPosting,
            subjectType: $context->subject->type,
            subjectId: $context->subject->id,
            subjectVersion: $context->subject->version,
            contextSnapshot: $contextSnapshot,
            contextHash: hash('sha256', $this->canonicalJson($contextSnapshot)),
            fundingSnapshot: $funding->snapshot,
            ruleSnapshot: $ruleSnapshot,
            calculationTrace: $calculationTrace,
            correlationId: $funding->correlationId,
            causationId: $funding->causationId,
        );
    }

    private function buildAllocation(SettlementPlan $plan, ComputedAllocation $c, int $sequence): SettlementAllocation
    {
        $allocation = new SettlementAllocation(
            plan: $plan,
            sequence: $sequence,
            allocationKey: $c->allocationKey,
            recipientType: $c->recipient->type,
            recipientId: $c->recipient->id,
            recipientSnapshot: $c->recipientSnapshot,
            ruleCode: $c->ruleCode,
            ruleVersionUuid: $c->ruleVersionUuid,
            reasonCode: $c->reasonCode,
            exactAmountQuantum: $c->exactAmountQuantum,
            postingAmount: $c->postingAmount,
            postingScale: $c->postingScale,
            roundingDeltaQuantum: $c->roundingDeltaQuantum,
            postingIdempotencyKey: 'settlement-credit:' . $plan->getUuid() . ':' . $c->allocationKey,
            sourceItemId: $c->sourceItemId,
            sourceItemSnapshot: $c->sourceItemSnapshot,
        );
        $allocation->setRoundingRank($c->roundingRank);
        return $allocation;
    }

    /**
     * @param list<array{0: SettlementAllocation, 1: ComputedAllocation}> $built
     */
    private function emitPostingCommands(SettlementPlan $plan, array $built): void
    {
        foreach ($built as [$entity]) {
            $this->outbox->record(
                'settlement.allocation.post.requested.v1',
                'settlement_allocation',
                $entity->getUuid(),
                ['allocationUuid' => $entity->getUuid(), 'planUuid' => $plan->getUuid()],
            );
        }
    }

    private function reconcilePlanState(SettlementPlan $plan): void
    {
        $allocations = $plan->getAllocations();
        $posted = 0;
        $reversed = 0;
        $pendingReversal = 0;
        $failed = 0;
        $cancelled = 0;
        $total = count($plan->getAllocations());

        foreach ($allocations as $allocation) {
            $status = $allocation->getStatus();
            if ($status === SettlementAllocation::STATUS_POSTED) {
                $posted++;
            }
            if ($status === SettlementAllocation::STATUS_REVERSED) {
                $reversed++;
            }
            if ($status === SettlementAllocation::STATUS_REVERSAL_PENDING) {
                $pendingReversal++;
            }
            if ($status === SettlementAllocation::STATUS_FAILED) {
                $failed++;
            }
            if ($status === SettlementAllocation::STATUS_CANCELLED) {
                $cancelled++;
            }
        }

        if ($pendingReversal > 0) {
            $plan->markReversalPending();
            return;
        }
        if ($posted > 0 && $posted < $total) {
            $plan->markPartiallyPosted();
            return;
        }
        if ($posted === $total && $total > 0) {
            $plan->markPosted();
            return;
        }
        if ($reversed === $total && $total > 0) {
            $plan->markReversed();
            return;
        }
        if ($failed > 0 || $cancelled === $total) {
            $plan->markFailed();
            return;
        }
        $plan->markPosting();
    }

    private function fingerprint(SettlementFunding $funding): string
    {
        return hash('sha256', $this->canonicalJson([
            $funding->fundingId,
            $funding->sourceType,
            $funding->sourceId,
            $funding->confirmationReference,
            $funding->currency,
            $funding->amountQuantum,
            $funding->calculationScale,
        ]));
    }

    /** @param array<mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }

    private function assertFingerprint(SettlementPlan $plan, SettlementFunding $funding): void
    {
        if ($plan->getFundingFingerprint() !== $this->fingerprint($funding)) {
            throw new SettlementException('Funding replay conflicts with the existing plan fingerprint');
        }
    }

    private function assertReceipt(\App\Settlement\Contract\VoucherPostingReceipt $receipt, string $idempotencyKey, string $status): void
    {
        if ($receipt->idempotencyKey !== $idempotencyKey || $receipt->status !== $status) {
            throw new SettlementException('Voucher receipt does not match the requested operation.');
        }
    }

    /**
     * @param list<ComputedAllocation> $computed
     */
    private function sumQuantum(array $computed): string
    {
        $b = BigInteger::zero();
        foreach ($computed as $c) {
            $b = $b->plus(BigInteger::of($c->exactAmountQuantum));
        }
        return $b->toBase(10);
    }
}
