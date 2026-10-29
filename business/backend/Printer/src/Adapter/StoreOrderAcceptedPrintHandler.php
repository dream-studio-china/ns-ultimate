<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Trade\Message\StoreOrderAcceptedMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StoreOrderAcceptedPrintHandler
{
    public function __construct(private readonly OrderPrintRuleEventAdapter $adapter)
    {
    }

    public function __invoke(StoreOrderAcceptedMessage $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        if (!is_array($payload) || !is_string($payload['orderUuid'] ?? null) || !is_string($payload['storeUuid'] ?? null)) {
            throw new \InvalidArgumentException('Invalid store.order.accepted.v1 envelope for printer adapter.');
        }
        $this->adapter->onAccepted($payload['orderUuid'], $payload['storeUuid']);
    }
}
