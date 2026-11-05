<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\DTO;

use App\Core\Utils\UUID;
use App\Trade\Entity\Order;

/** Value projection of a receipt stored in Order metadata; not an ORM entity. */
final class OrderRefundReceipt
{
    public private(set) string $uuid;
    public private(set) string $storeUuid;
    public private(set) string $orderUuid;
    public private(set) string $invoiceUuid;
    public private(set) string $actorUuid;
    public private(set) string $requestHash;
    public private(set) int $amount;
    public private(set) string $reason;
    public private(set) string $payment;
    public string $status = 'processing';
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(string $storeUuid, string $orderUuid, string $invoiceUuid, string $actorUuid, string $requestHash, int $amount, string $reason, string $payment)
    {
        $this->uuid = UUID::v4();
        $this->storeUuid = $storeUuid;
        $this->orderUuid = $orderUuid;
        $this->invoiceUuid = $invoiceUuid;
        $this->actorUuid = $actorUuid;
        $this->requestHash = $requestHash;
        $this->amount = $amount;
        $this->reason = $reason;
        $this->payment = $payment;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function coreKey(): string { return 'store-order-refund:'.$this->uuid; }
    public function huifuNumber(): string { return 'SOR'.str_replace('-', '', $this->uuid); }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['uuid' => $this->uuid, 'invoiceUuid' => $this->invoiceUuid, 'actorUuid' => $this->actorUuid,
            'amount' => $this->amount, 'reason' => $this->reason, 'payment' => $this->payment,
            'submissionRejected' => $this->status === 'failed', 'createdAt' => $this->createdAt->format(DATE_ATOM)];
    }

    /** @param array<string, mixed> $entry */
    public static function fromArray(Order $order, string $storeUuid, string $hash, array $entry): self
    {
        $receipt = new self($storeUuid, $order->getUuid(), $entry['invoiceUuid'], $entry['actorUuid'], $hash,
            $entry['amount'], $entry['reason'], $entry['payment']);
        $receipt->uuid = $entry['uuid'];
        $receipt->status = ($entry['submissionRejected'] ?? false) === true ? 'failed' : 'processing';
        $receipt->createdAt = new \DateTimeImmutable($entry['createdAt']);
        return $receipt;
    }
}
