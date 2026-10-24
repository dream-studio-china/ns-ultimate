<?php

declare(strict_types=1);

use NsUltimate\Integration\Backend\Kernel;
use Symfony\Component\Dotenv\Dotenv;

$repositoryRoot = dirname(__DIR__, 2);
$coreDirectory = $repositoryRoot.'/core/crud-skeleton';

/** @var Composer\Autoload\ClassLoader $classLoader */
$classLoader = require $coreDirectory.'/vendor/autoload.php';
require __DIR__.'/vendor/autoload.php';
$classLoader->addPsr4('NsUltimate\\Integration\\Backend\\', __DIR__.'/src/');
$modules = NsUltimate\Integration\Backend\ModuleRegistry::discover($repositoryRoot.'/business/backend');
foreach ($modules as $module) {
    $classLoader->addPsr4($module['namespace'], $module['path'].'/src/');
}

$_SERVER['APP_ENV'] ??= 'dev';
$_ENV['APP_ENV'] ??= $_SERVER['APP_ENV'];
// Resolve project-owned env paths independently of the caller's working directory.
$_SERVER['NS_PROJECT_ROOT'] = $repositoryRoot;
$_ENV['NS_PROJECT_ROOT'] = $repositoryRoot;
putenv('NS_PROJECT_ROOT='.$repositoryRoot);

(new Dotenv())->bootEnv(__DIR__.'/.env');

// Static analysis only needs Doctrine metadata. Force an isolated SQLite database
// so analysis never requires the MySQL dev database from .env.local.
$phpstanDatabaseUrl = 'sqlite:///'.$coreDirectory.'/var/phpstan-business.db';
$_SERVER['DATABASE_URL'] = $phpstanDatabaseUrl;
$_ENV['DATABASE_URL'] = $phpstanDatabaseUrl;
putenv('DATABASE_URL='.$phpstanDatabaseUrl);

$kernel = new Kernel('dev', false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine.orm.entity_manager');
