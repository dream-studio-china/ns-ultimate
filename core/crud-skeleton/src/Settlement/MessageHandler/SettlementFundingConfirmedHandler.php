<?php

declare(strict_types=1);

namespace App\Settlement\MessageHandler;

use App\Settlement\Contract\SettlementFunding;
use App\Settlement\Entity\SettlementConsumedEvent;
use App\Settlement\Message\SettlementFundingConfirmedMessage;
use App\Settlement\Repository\SettlementConsumedEventRepository;
use App\Settlement\Service\SettlementServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Inbox deduplication + plan creation for a confirmed funding envelope.
 */
#[AsMessageHandler]
final class SettlementFundingConfirmedHandler
{
    public function __construct(
        private readonly SettlementServiceInterface $settlementService,
        private readonly SettlementConsumedEventRepository $consumedEventRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SettlementFundingConfirmedMessage $message): void
    {
        if ($this->consumedEventRepository->exists($message->eventId)) {
            return;
        }

        $payloadHash = hash('sha256', (string) json_encode([
            $message->fundingId,
            $message->currency,
            $message->amountQuantum,
            $message->confirmationReference,
        ]));

        $funding = new SettlementFunding(
            fundingId: $message->fundingId,
            sourceType: $message->sourceType,
            sourceId: $message->sourceId,
            confirmationReference: $message->confirmationReference,
            currency: $message->currency,
            amountQuantum: $message->amountQuantum,
            calculationScale: $message->calculationScale,
            confirmedAt: new \DateTimeImmutable($message->occurredAt),
            idempotencyKey: $message->idempotencyKey,
            correlationId: $message->correlationId,
            causationId: $message->causationId,
            fundingKind: $message->fundingKind,
            snapshot: $message->snapshot,
        );

        $this->em->wrapInTransaction(function () use ($funding, $message, $payloadHash): void {
            $this->settlementService->createPlanFromFunding($funding);
            $this->em->persist(new SettlementConsumedEvent(
                $message->eventId,
                'settlement.funding.confirmed.v1',
                $message->sourceType,
                $message->sourceId,
                $payloadHash,
            ));
        });
    }
}
