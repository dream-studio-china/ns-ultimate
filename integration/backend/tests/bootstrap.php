<?php

declare(strict_types=1);

$databaseFile = sys_get_temp_dir().'/ns-ultimate-integration-'.bin2hex(random_bytes(8)).'.sqlite';
$databaseUrl = 'sqlite:///'.str_replace('\\', '/', $databaseFile);
$accessLog = sys_get_temp_dir().'/ns-ultimate-integration-'.bin2hex(random_bytes(8)).'.log';

foreach ([
    'APP_ENV' => 'test',
    'APP_DEBUG' => '1',
    'DATABASE_URL' => $databaseUrl,
    'TEST_ACCESS_LOG' => $accessLog,
] as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

require dirname(__DIR__).'/bootstrap.php';

// Shared test-only synthetic manifest helper for legacy adapter safety suites.
require_once dirname(__DIR__, 3).'/apps/admin-agent/backend/tests/Integration/Support/AdminConfigurationTestManifest.php';
\NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::install();

register_shutdown_function(static function () use ($databaseFile, $accessLog): void {
    foreach ([$databaseFile, $databaseFile.'-journal', $databaseFile.'-wal', $databaseFile.'-shm', $accessLog] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});
