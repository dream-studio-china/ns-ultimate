<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Settlement\Contract\SettlementFunding;
use App\Settlement\Entity\SettlementPlan;

interface SettlementServiceInterface
{
    /**
     * Create a plan (and its allocations) from a confirmed funding fact, idempotently.
     * Returns an existing plan when the funding was already processed.
     */
    public function createPlanFromFunding(SettlementFunding $funding): SettlementPlan;

    /**
     * Process one allocation's posting command against the voucher port.
     */
    public function postAllocation(string $allocationUuid): void;

    /**
     * Process one allocation's reversal command against the voucher port.
     */
    public function reverseAllocation(string $allocationUuid, string $reversalUuid, string $reasonCode, string $reasonDetail, string $requestedBy): void;
}
