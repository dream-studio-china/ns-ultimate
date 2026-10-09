<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Ensure tests run with APP_ENV=test when an external runner (IDE) doesn't set it.
$_SERVER['APP_ENV'] ??= 'test';
$_ENV['APP_ENV'] ??= 'test';

// Detect an explicitly-provided DATABASE_URL (a real environment variable)
// BEFORE Dotenv populates $_SERVER/$_ENV from .env/.env.test, so a
// Postgres/MySQL URL (e.g. CI) is never clobbered by the sqlite fallback.
$explicitDatabaseUrl = getenv('DATABASE_URL');

if (method_exists(Dotenv::class, 'bootEnv')) {
    // Load .env and the env-specific file (.env.test) when APP_ENV=test.
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Provide a safe fallback for DATABASE_URL when env files are not loaded (IDE runners).
// Prefer an sqlite file inside var/test.db to avoid requiring external DB in tests.
if ($explicitDatabaseUrl === false) {
    $_SERVER['DATABASE_URL'] ??= "sqlite:///%kernel.project_dir%/var/test.db";
    $_ENV['DATABASE_URL'] ??= "sqlite:///%kernel.project_dir%/var/test.db";
}

if (($_SERVER['PARATEST'] ?? getenv('PARATEST')) === '1') {
    // ------------------------------------------------------------------
    // Parallel runner (paratest). Give every worker process an isolated
    // database so concurrent schema drop/create calls never race. Local runs
    // use sqlite files; CI supplies a PostgreSQL URL template keyed by token.
    //
    // NOTE: the main paratest process also executes this bootstrap (for test
    // discovery) and, like workers, putenv()s its own per-PID URL. That value
    // is inherited by every spawned worker, so a worker's getenv() at this
    // point is usually NOT empty — it is the main process's leaked URL. We
    // therefore also override when the current URL already looks like a
    // paratest sqlite file (or the default sqlite), so every worker ends up
    // with its own DB. CI passes PARATEST_DATABASE_URL_TEMPLATE for external
    // databases; a shared external database remains unsupported.
    // ------------------------------------------------------------------
    $pid = getmypid();
    $currentUrl = getenv('DATABASE_URL') ?: ($_SERVER['DATABASE_URL'] ?? '');
    $token = getenv('TEST_TOKEN');
    $databaseUrlTemplate = getenv('PARATEST_DATABASE_URL_TEMPLATE');
    $isolatedDatabase = false;
    $usePerProcessSqlite = $explicitDatabaseUrl === false
        || $currentUrl === ''
        || str_contains($currentUrl, 'test_paratest_')
        || str_contains($currentUrl, '/var/test.db');
    if ($usePerProcessSqlite) {
        $url = 'sqlite:///'.dirname(__DIR__).'/var/test_paratest_'.$pid.'.db';
        $_SERVER['DATABASE_URL'] = $url;
        $_ENV['DATABASE_URL'] = $url;
        putenv('DATABASE_URL='.$url);
        $isolatedDatabase = true;
    } elseif (is_string($databaseUrlTemplate) && $databaseUrlTemplate !== ''
        && is_string($token) && ctype_digit($token)) {
        $url = str_replace('{token}', $token, $databaseUrlTemplate);
        $_SERVER['DATABASE_URL'] = $url;
        $_ENV['DATABASE_URL'] = $url;
        putenv('DATABASE_URL='.$url);
        $isolatedDatabase = true;
    }

    if ($isolatedDatabase) {
        // Eagerly create the schema so tests that query the DB without the
        // DatabaseBootstrapTrait find tables already present in this worker.
        try {
            $class = 'App\\Kernel';
            if (!class_exists($class, false)) {
                require dirname(__DIR__).'/src/Kernel.php';
            }
            $kernel = new $class('test', false);
            $kernel->boot();
            $em = $kernel->getContainer()->get('doctrine')->getManager();
            $em->getConnection()->executeStatement('SELECT 1');
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $schemaTool->createSchema($metadata);
            $kernel->shutdown();
        } catch (\Throwable $e) {
            // Never mask the real test; the DatabaseBootstrapTrait will rebuild if needed.
        }
    }
    // Per-process access log so concurrent workers don't write to one file.
    $_SERVER['TEST_ACCESS_LOG'] = dirname(__DIR__).'/var/log/access-'.$pid.'.log';
    $_ENV['TEST_ACCESS_LOG'] = $_SERVER['TEST_ACCESS_LOG'];
    putenv('TEST_ACCESS_LOG='.$_SERVER['TEST_ACCESS_LOG']);
} else {
    // Per-process access log is only needed under a parallel runner; use the
    // default path otherwise so %env(resolve:TEST_ACCESS_LOG)% always resolves.
    $_SERVER['TEST_ACCESS_LOG'] ??= dirname(__DIR__).'/var/log/access.log';
    $_ENV['TEST_ACCESS_LOG'] ??= dirname(__DIR__).'/var/log/access.log';
}

// Make Symfony KernelTestCase/WebTestCase robust in CLI/IDE test runners.
$_SERVER['KERNEL_CLASS'] ??= 'App\\Kernel';
$_ENV['KERNEL_CLASS'] ??= 'App\\Kernel';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
