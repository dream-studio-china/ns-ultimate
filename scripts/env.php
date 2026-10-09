<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$command = $argv[1] ?? '';
umask(0077);

function writeOnce(string $path, string $contents): void
{
    if (is_link($path)) {
        throw new RuntimeException('Refusing to write through symlink '.$path);
    }
    if (file_exists($path)) {
        echo 'Preserved: '.basename(dirname($path)).'/'.basename($path).PHP_EOL;
        return;
    }
    $handle = fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('Cannot create '.$path);
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Cannot write '.$path);
        }
    } finally {
        fclose($handle);
    }
    echo 'Created: '.basename(dirname($path)).'/'.basename($path).PHP_EOL;
}

if ($command === 'env-init') {
    $resolvePath = static function (string $path) use ($root): string {
        return str_starts_with($path, '/') ? $path : $root.'/'.$path;
    };
    $dataDirectory = $resolvePath(getenv('NS_DEV_DATA_DIR') ?: 'var/data');
    $keysDirectory = $resolvePath(getenv('NS_DEV_KEYS_DIR') ?: 'var/keys');
    if (str_starts_with($dataDirectory, $root.'/var/') && is_link($root.'/var')) {
        throw new RuntimeException('Refusing to use a symlinked project var directory.');
    }
    if (str_starts_with($keysDirectory, $root.'/var/') && is_link($root.'/var')) {
        throw new RuntimeException('Refusing to use a symlinked project var directory.');
    }
    foreach ([$dataDirectory, $keysDirectory] as $directory) {
        if (is_link($directory)) {
            throw new RuntimeException('Refusing to use symlinked development directory '.$directory);
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create '.$directory);
        }
    }
    writeOnce($root.'/integration/admin/.env.local', file_get_contents($root.'/integration/admin/.env.local.example'));
    if (getenv('NS_SKIP_BACKEND_SECRET_FILE') !== '1') {
        $backendEnvFile = $resolvePath(getenv('NS_DEV_BACKEND_ENV_FILE') ?: 'integration/backend/.env.local');
        $backendSecrets = sprintf(
            "# Generated development secrets. Do not commit this file.\nAPP_SECRET=%s\nREFRESH_TOKEN_SECRET=%s\n",
            bin2hex(random_bytes(32)),
            bin2hex(random_bytes(32)),
        );
        if ($shareDirectory = getenv('NS_DEV_APP_SHARE_DOTENV')) {
            $backendSecrets .= 'APP_SHARE_DIR="'.$shareDirectory."\"\n";
        }
        if ($privateKeyPath = getenv('NS_DEV_PRIVATE_KEY_DOTENV')) {
            $backendSecrets .= 'JWT_PRIVATE_KEY_PATH="'.$privateKeyPath."\"\n";
        }
        if ($publicKeyPath = getenv('NS_DEV_PUBLIC_KEY_DOTENV')) {
            $backendSecrets .= 'JWT_PUBLIC_KEY_PATH="'.$publicKeyPath."\"\n";
        }
        writeOnce($backendEnvFile, $backendSecrets);
    }
    $private = rtrim($keysDirectory, '/').'/private.pem';
    $public = rtrim($keysDirectory, '/').'/public.pem';
    if (is_link($private) || is_link($public)) {
        throw new RuntimeException('Refusing to use symlinked development JWT keys.');
    }
    if (!file_exists($private) && !file_exists($public)) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Cannot generate development JWT keys. Check the OpenSSL extension.');
        }
        writeOnce($private, $pem);
        writeOnce($public, openssl_pkey_get_details($key)['key']);
    } elseif (!file_exists($private) || !file_exists($public)) {
        throw new RuntimeException('Incomplete JWT key pair. Restore the missing key; existing keys are never replaced.');
    }
    exit(0);
}

if ($command === 'env-check') {
    $failed = false;
    $isLocalDevReady = is_file($root.'/integration/backend/.env.dev.local');
    $backendEnvFile = $isLocalDevReady ? 'integration/backend/.env.dev.local' : 'integration/backend/.env.local';
    $keysDirectory = $isLocalDevReady ? 'var/local-dev/keys' : 'var/keys';
    foreach (['integration/admin/.env.local', $backendEnvFile, $keysDirectory.'/private.pem', $keysDirectory.'/public.pem'] as $file) {
        $exists = is_file($root.'/'.$file);
        echo ($exists ? 'OK: ' : 'Missing: ').$file.PHP_EOL;
        $failed = $failed || !$exists;
    }
    if (!$failed) {
        require $root.'/integration/backend/bootstrap.php';
        foreach (['APP_SECRET', 'REFRESH_TOKEN_SECRET', 'DATABASE_URL'] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? '';
            $valid = is_string($value) && $value !== '' && !str_starts_with($value, 'replace-with-');
            echo ($valid ? 'Configured: ' : 'Unset or placeholder: ').$name.PHP_EOL;
            $failed = $failed || !$valid;
        }
    }
    exit($failed ? 1 : 0);
}

fwrite(STDERR, "Usage: php scripts/env.php env-init|env-check\n");
exit(2);
