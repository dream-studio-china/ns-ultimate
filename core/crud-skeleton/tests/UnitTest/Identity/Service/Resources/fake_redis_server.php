<?php

declare(strict_types=1);

/**
 * Minimal in-memory RESP server speaking just enough of the Redis protocol
 * for RedisOtpStorage unit tests. NOT a general-purpose Redis server.
 *
 * Usage: php fake_redis_server.php <port> <pidFile>
 *
 * Behavior:
 *  - Keys prefixed with "err_" answer with a RESP error (-ERR ...) so the
 *    defensive non-int/non-string coercion branches of RedisOtpStorage can
 *    be exercised (Predis returns Predis\Response\Error objects because the
 *    connection is created with exceptions=false).
 *  - Every other key behaves like a normal in-memory key store with TTLs.
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php fake_redis_server.php <port> <pidFile>\n");
    exit(2);
}

$port = (int) $argv[1];
$pidFile = $argv[2] ?? '';

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot bind {$port}: {$errstr}\n");
    exit(1);
}

if ($pidFile !== '') {
    file_put_contents($pidFile, (string) getmypid());
}

$store = [];
$ttls = [];

while (true) {
    $conn = @stream_socket_accept($server, 60);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 60);
    serveConnection($conn, $store, $ttls);
    @fclose($conn);
}

/**
 * @param array<string, string> $store
 * @param array<string, float>  $ttls
 */
function serveConnection(mixed $conn, array &$store, array &$ttls): void
{
    while (!feof($conn)) {
        $line = fgets($conn);
        if ($line === false) {
            return;
        }
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($line === '' || $line[0] !== '*') {
            writeError($conn, 'ERR invalid request');
            continue;
        }

        $count = (int) substr($line, 1);
        $args = [];
        for ($i = 0; $i < $count; $i++) {
            $hdr = trim((string) fgets($conn));
            if ($hdr === '' || $hdr[0] !== '$') {
                return;
            }
            $len = (int) substr($hdr, 1);
            $data = (string) fread($conn, $len + 2);
            $args[] = substr($data, 0, $len);
        }

        if ($args === []) {
            writeError($conn, 'ERR empty command');
            continue;
        }

        $cmd = strtoupper($args[0]);
        $params = array_slice($args, 1);

        purgeExpired($store, $ttls);

        match ($cmd) {
            'SELECT' => writeSimple($conn, 'OK'),
            'PING' => writeSimple($conn, 'PONG'),
            'EXISTS' => handleExists($conn, $params, $store),
            'GET' => handleGet($conn, $params, $store),
            'SETEX' => handleSetex($conn, $params, $store, $ttls),
            'DEL' => handleDel($conn, $params, $store, $ttls),
            'TTL' => handleTtl($conn, $params, $store, $ttls),
            default => writeSimple($conn, 'OK'),
        };
    }
}

/**
 * @param list<string>          $params
 * @param array<string, string> $store
 */
function handleExists(mixed $conn, array $params, array &$store): void
{
    $key = $params[0] ?? '';
    if (str_starts_with($key, 'err_')) {
        writeError($conn, 'ERR fake failure for EXISTS');
        return;
    }
    writeInteger($conn, isset($store[$key]) ? 1 : 0);
}

/**
 * @param list<string>           $params
 * @param array<string, string>  $store
 */
function handleGet(mixed $conn, array $params, array &$store): void
{
    $key = $params[0] ?? '';
    if (str_starts_with($key, 'err_')) {
        writeError($conn, 'ERR fake failure for GET');
        return;
    }
    if (isset($store[$key])) {
        writeBulk($conn, $store[$key]);
        return;
    }
    writeNullBulk($conn);
}

/**
 * @param list<string>           $params
 * @param array<string, string>  $store
 * @param array<string, float>   $ttls
 */
function handleSetex(mixed $conn, array $params, array &$store, array &$ttls): void
{
    $key = $params[0] ?? '';
    $ttl = (int) ($params[1] ?? 0);
    $value = $params[2] ?? '';
    if (str_starts_with($key, 'err_')) {
        writeError($conn, 'ERR fake failure for SETEX');
        return;
    }
    $store[$key] = $value;
    $ttls[$key] = microtime(true) + $ttl;
    writeSimple($conn, 'OK');
}

/**
 * @param list<string>           $params
 * @param array<string, string>  $store
 * @param array<string, float>   $ttls
 */
function handleDel(mixed $conn, array $params, array &$store, array &$ttls): void
{
    if (str_starts_with($params[0] ?? '', 'err_')) {
        writeError($conn, 'ERR fake failure for DEL');
        return;
    }
    $deleted = 0;
    foreach ($params as $key) {
        if (isset($store[$key])) {
            unset($store[$key], $ttls[$key]);
            ++$deleted;
        }
    }
    writeInteger($conn, $deleted);
}

/**
 * @param list<string>           $params
 * @param array<string, string>  $store
 * @param array<string, float>   $ttls
 */
function handleTtl(mixed $conn, array $params, array &$store, array &$ttls): void
{
    $key = $params[0] ?? '';
    if (str_starts_with($key, 'err_')) {
        writeError($conn, 'ERR fake failure for TTL');
        return;
    }
    if (!isset($store[$key])) {
        writeInteger($conn, -2);
        return;
    }
    if (!isset($ttls[$key])) {
        writeInteger($conn, -1);
        return;
    }
    writeInteger($conn, (int) max(0, floor($ttls[$key] - microtime(true))));
}

/**
 * @param array<string, string> $store
 * @param array<string, float>  $ttls
 */
function purgeExpired(array &$store, array &$ttls): void
{
    $now = microtime(true);
    foreach ($ttls as $key => $expiresAt) {
        if ($expiresAt < $now) {
            unset($store[$key], $ttls[$key]);
        }
    }
}

function writeSimple(mixed $conn, string $value): void
{
    fwrite($conn, "+{$value}\r\n");
}

function writeError(mixed $conn, string $message): void
{
    fwrite($conn, "-{$message}\r\n");
}

function writeInteger(mixed $conn, int $value): void
{
    fwrite($conn, ":{$value}\r\n");
}

function writeBulk(mixed $conn, string $value): void
{
    fwrite($conn, '$' . strlen($value) . "\r\n{$value}\r\n");
}

function writeNullBulk(mixed $conn): void
{
    fwrite($conn, "\$-1\r\n");
}
