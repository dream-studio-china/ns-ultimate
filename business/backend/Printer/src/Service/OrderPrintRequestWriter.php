<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;

/** Insert request snapshots atomically without turning a replay into a transaction failure. */
final class OrderPrintRequestWriter
{
    public function __construct(private readonly Connection $connection) {}

    public function insert(PrinterOrderRequest $request): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Order print requests must be written inside the order transaction.');
        }
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            $suffix = ' ON CONFLICT(request_key) DO NOTHING';
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            // Other uniqueness failures must still fail; do not use INSERT IGNORE.
            $suffix = ' ON DUPLICATE KEY UPDATE request_key = IF(request_key = VALUES(request_key), request_key, NULL)';
        } else {
            throw new \LogicException('Atomic order print request insertion is unsupported on this database platform.');
        }
        $this->connection->executeStatement(
            'INSERT INTO printer_order_request (uuid, request_key, order_uuid, store_uuid, case_key, event_trigger, configuration, print_data, status, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'.$suffix,
            [
                $request->getUuid(), $request->getRequestKey(), $request->getOrderUuid(), $request->getStoreUuid(),
                $request->getCaseKey(), $request->getTrigger(),
                json_encode($request->getConfiguration(), JSON_THROW_ON_ERROR),
                json_encode($request->getPrintData(), JSON_THROW_ON_ERROR),
                $request->getStatus(), null, $request->getCreatedAt()->format('Y-m-d H:i:s'),
            ],
        );
    }
}
