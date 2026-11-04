<?php

declare(strict_types=1);

$databaseFile = sys_get_temp_dir().'/ns-ultimate-integration-'.bin2hex(random_bytes(8)).'.sqlite';
$databaseUrl = 'sqlite:///'.str_replace('\\', '/', $databaseFile);
$accessLog = sys_get_temp_dir().'/ns-ultimate-integration-'.bin2hex(random_bytes(8)).'.log';
$auditLog = sys_get_temp_dir().'/ns-ultimate-integration-audit-'.bin2hex(random_bytes(8)).'.log';

foreach ([
    'APP_ENV' => 'test',
    'APP_DEBUG' => '1',
    'DATABASE_URL' => $databaseUrl,
    'TEST_ACCESS_LOG' => $accessLog,
    'AUDIT_LOG_PATH' => $auditLog,
] as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

require dirname(__DIR__).'/bootstrap.php';

register_shutdown_function(static function () use ($databaseFile, $accessLog, $auditLog): void {
    foreach ([$databaseFile, $databaseFile.'-journal', $databaseFile.'-wal', $databaseFile.'-shm', $accessLog, $auditLog] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    // rotating_file appends the current date to the configured filename.
    foreach (glob(pathinfo($auditLog, PATHINFO_DIRNAME).'/'.pathinfo($auditLog, PATHINFO_FILENAME).'-*.log') ?: [] as $file) {
        unlink($file);
    }
});
