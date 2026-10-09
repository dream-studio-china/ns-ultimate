<?php

declare(strict_types=1);

namespace App\Settlement\MessageHandler;

use App\Settlement\Message\SettlementAllocationPostingMessage;
use App\Settlement\Service\SettlementServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SettlementAllocationPostingHandler
{
    public function __construct(
        private readonly SettlementServiceInterface $settlementService,
    ) {
    }

    public function __invoke(SettlementAllocationPostingMessage $message): void
    {
        $this->settlementService->postAllocation($message->allocationUuid);
    }
}
