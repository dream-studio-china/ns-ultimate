<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use App\Core\Utils\UUID;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;

/** Insert request snapshots atomically without turning a replay into a transaction failure. */
final class OrderPrintRequestWriter
{
    public function __construct(private readonly Connection $connection) {}

    /** Claims older than this can no longer affect a live order; keep the table bounded. */
    public const CLAIM_RETENTION_DAYS = 90;

    public function pruneClaims(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM printer_order_rule_claim WHERE created_at < ?',
            [$now->modify('-'.self::CLAIM_RETENTION_DAYS.' days')->format('Y-m-d H:i:s')],
        );
    }

    /** Claim an order/rule once in the caller's transaction, even under concurrent delivery. */
    public function claim(string $orderUuid, string $ruleUuid): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Order print rules must be claimed inside the order transaction.');
        }
        $key = hash('sha256', json_encode([$orderUuid, $ruleUuid], JSON_THROW_ON_ERROR));
        $token = UUID::v4();
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            $suffix = ' ON CONFLICT(claim_key) DO NOTHING';
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $suffix = ' ON DUPLICATE KEY UPDATE claim_key = claim_key';
        } else {
            throw new \LogicException('Atomic order print rule claims are unsupported on this database platform.');
        }
        $this->connection->executeStatement(
            'INSERT INTO printer_order_rule_claim (claim_key, token, created_at) VALUES (?, ?, ?)'.$suffix,
            [$key, $token, (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
        $lockingRead = $platform instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
        return $this->connection->fetchOne('SELECT token FROM printer_order_rule_claim WHERE claim_key = ?'.$lockingRead, [$key]) === $token;
    }

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
                $request->getStatus(), $request->getErrorMessage(), $request->getCreatedAt()->format('Y-m-d H:i:s'),
            ],
        );
    }
}
