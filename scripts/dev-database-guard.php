<?php

declare(strict_types=1);

/**
 * @return array{hostname: string, port: int}
 */
function getMySqlServerIdentity(PDO $connection): array
{
    $identity = $connection->query('SELECT @@hostname AS hostname, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity) || !is_string($identity['hostname'] ?? null) || !is_numeric($identity['port'] ?? null)) {
        throw new RuntimeException('Cannot verify the MySQL server identity; refusing local database setup/reset.');
    }

    return [
        'hostname' => $identity['hostname'],
        'port' => (int) $identity['port'],
    ];
}
