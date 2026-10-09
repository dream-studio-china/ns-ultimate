<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$command = $argv[1] ?? '';
umask(0077);

function writeOnce(string $path, string $contents): void
{
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
    foreach (['var/data', 'var/keys'] as $directory) {
        if (!is_dir($root.'/'.$directory) && !mkdir($root.'/'.$directory, 0700, true)) {
            throw new RuntimeException('Cannot create '.$directory);
        }
    }
    writeOnce($root.'/integration/admin/.env.local', file_get_contents($root.'/integration/admin/.env.local.example'));
    writeOnce($root.'/integration/backend/.env.local', sprintf(
        "# Generated local secrets. Do not commit this file.\nAPP_SECRET=%s\nREFRESH_TOKEN_SECRET=%s\n",
        bin2hex(random_bytes(32)),
        bin2hex(random_bytes(32)),
    ));
    $private = $root.'/var/keys/private.pem';
    $public = $root.'/var/keys/public.pem';
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
    foreach (['integration/admin/.env.local', 'integration/backend/.env.local', 'var/keys/private.pem', 'var/keys/public.pem'] as $file) {
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
