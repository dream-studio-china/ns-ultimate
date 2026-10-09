<?php

declare(strict_types=1);

namespace App\Settlement\Port;

use App\Settlement\Contract\ConfirmedAllocation;
use App\Settlement\Contract\PostedAllocation;
use App\Settlement\Contract\ReversalRequest;
use App\Settlement\Contract\VoucherPostingReceipt;

/**
 * One-way handoff boundary owned by Settlement. Implemented by the host (Wallet).
 * `post()` is the moment ownership transfers to the recipient; Settlement performs
 * no further management of a posted amount except reversing the original voucher.
 */
interface SettlementVoucherPort
{
    public function post(ConfirmedAllocation $allocation): VoucherPostingReceipt;

    public function reverse(PostedAllocation $allocation, ReversalRequest $request): VoucherPostingReceipt;
}
