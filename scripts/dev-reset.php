<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

try {
    $requestedEnvironment = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '');
    if (in_array(strtolower((string) $requestedEnvironment), ['prod', 'production'], true)) {
        fail('Refusing to run a development reset while APP_ENV is production.');
    }
    putenv('APP_ENV=dev');
    putenv('DATABASE_URL');
    unset($_SERVER['APP_ENV'], $_ENV['APP_ENV'], $_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);

    $devEnvFile = $root.'/integration/backend/.env.dev.local';
    if (is_link($devEnvFile) || !is_file($devEnvFile)) {
        fail('The isolated development env file is missing; refusing to infer a reset target.');
    }
    $devEnvStat = stat($devEnvFile);
    if (!is_array($devEnvStat) || ($devEnvStat['nlink'] ?? 0) > 1) {
        fail('The isolated development env file has multiple hard links; refusing to modify it.');
    }
    $devEnvContents = file_get_contents($devEnvFile);
    if (!is_string($devEnvContents) || preg_match('/^\s*DATABASE_URL\s*=/m', $devEnvContents) !== 1) {
        fail('The isolated development env file has no explicit DATABASE_URL; refusing to reset.');
    }
    require $root.'/integration/backend/bootstrap.php';

    $databaseUrl = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '');
    $parts = parse_url($databaseUrl);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'mysql') {
        fail('Reset is limited to the local MySQL development database named ns_ultimate.');
    }

    $host = (string) ($parts['host'] ?? '');
    $database = ltrim((string) ($parts['path'] ?? ''), '/');
    $port = (int) ($parts['port'] ?? 3306);
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true) || $database !== 'ns_ultimate') {
        fail('Refusing to reset this database. Only a loopback MySQL database named ns_ultimate is allowed.');
    }
    if ($port < 1 || $port > 65535) {
        fail('Refusing to reset a database with an invalid MySQL port.');
    }
    $tokenFile = $root.'/var/local-dev/database-token.txt';
    if (is_link($root.'/var') || is_link($root.'/var/local-dev') || is_link($root.'/var/local-dev/data') || is_link($root.'/var/local-dev/keys') || is_link(dirname($tokenFile)) || is_link($tokenFile) || !is_file($tokenFile)) {
        fail('The local development database ownership token is missing; refusing to reset.');
    }
    $localToken = trim((string) file_get_contents($tokenFile));
    if (preg_match('/^[a-f0-9]{64}$/', $localToken) !== 1) {
        fail('The local development database ownership token is invalid; refusing to reset.');
    }
    if (!extension_loaded('pdo_mysql')) {
        fail('The pdo_mysql extension is required.');
    }
    $user = rawurldecode((string) ($parts['user'] ?? ''));
    $password = rawurldecode((string) ($parts['pass'] ?? ''));
    $connection = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    require_once $root.'/scripts/dev-database-guard.php';
    $serverIdentity = getMySqlServerIdentity($connection);
    $guard = $connection->query('SELECT token FROM ns_ultimate_dev_guard WHERE id = 1')->fetchColumn();
    if (!is_string($guard) || !hash_equals($guard, $localToken)) {
        fail('The database ownership token does not match this checkout. Refusing to delete it.');
    }
    $displayHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host;
    $target = sprintf('%s:%d/%s (server %s:%d)', $displayHost, $port, $database, $serverIdentity['hostname'], $serverIdentity['port']);
    if (!stream_isatty(STDIN)) {
        fail(sprintf('A terminal is required. Reset requires typing the exact database target "%s".', $target));
    }

    fwrite(STDOUT, sprintf("This will permanently delete all data in the registered local development database %s and rotate development-only keys.\n", $target));
    fwrite(STDOUT, sprintf('Type the exact target "%s" to continue: ', $target));
    if (trim((string) fgets(STDIN)) !== $target) {
        fwrite(STDOUT, "Cancelled; no changes were made.\n");
        exit(0);
    }

    $readyFile = $root.'/var/local-dev/data/.ready';
    if (is_link($readyFile)) {
        fail('Refusing to remove a symlinked local development setup marker.');
    }
    if (is_file($readyFile) && !unlink($readyFile)) {
        fail('Cannot clear local development setup marker.');
    }

    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $server->exec('DROP DATABASE `ns_ultimate`');
    $server->exec('CREATE DATABASE `ns_ultimate` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $localSecrets = $devEnvFile;
    if (is_file($localSecrets)) {
        $contents = file_get_contents($localSecrets);
        if (!is_string($contents)) {
            fail('Cannot read integration/backend/.env.dev.local.');
        }
        foreach (['APP_SECRET', 'REFRESH_TOKEN_SECRET'] as $name) {
            $line = $name.'='.bin2hex(random_bytes(32));
            if (preg_match('/^'.preg_quote($name, '/').'\s*=.*$/m', $contents) === 1) {
                $contents = preg_replace('/^'.preg_quote($name, '/').'\s*=.*$/m', $line, $contents, 1) ?? $contents;
            } else {
                $contents .= (str_ends_with($contents, "\n") ? '' : "\n").$line."\n";
            }
        }
        if (file_put_contents($localSecrets, $contents, LOCK_EX) === false || !chmod($localSecrets, 0600)) {
            fail('Cannot rotate local application secrets.');
        }
    }

    foreach (['private.pem', 'public.pem', 'admin-initial-password.txt', 'admin-email.txt'] as $file) {
        $path = $root.'/var/local-dev/keys/'.$file;
        if (is_file($path) && !unlink($path)) {
            fail('Cannot remove '.$path);
        }
    }
    fwrite(STDOUT, "Local development database and keys reset. Run make dev to initialize again.\n");
} catch (Throwable $exception) {
    fail('Development reset failed: '.$exception->getMessage());
}
