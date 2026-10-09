<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$repositoryRoot = dirname(__DIR__, 2);
$coreDirectory = $repositoryRoot.'/core/crud-skeleton';
$autoloadFile = $coreDirectory.'/vendor/autoload.php';

if (!is_file($autoloadFile)) {
    throw new RuntimeException(sprintf(
        'Backend dependencies are missing. Run Composer install in "%s".',
        $coreDirectory,
    ));
}

/** @var Composer\Autoload\ClassLoader $classLoader */
$classLoader = require $autoloadFile;
$classLoader->addPsr4('NsUltimate\\Integration\\Backend\\', __DIR__.'/src/');
$modules = NsUltimate\Integration\Backend\ModuleRegistry::discover($repositoryRoot.'/business/backend');
foreach ($modules as $module) {
    $classLoader->addPsr4($module['namespace'], $module['path'].'/src/');
}

$appEnvironment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if (!is_string($appEnvironment) || $appEnvironment === '') {
    $appEnvironment = 'dev';
}
$_SERVER['APP_ENV'] = $appEnvironment;
$_ENV['APP_ENV'] = $appEnvironment;
// Resolve project-owned env paths independently of the caller's working directory.
$_SERVER['NS_PROJECT_ROOT'] = $repositoryRoot;
$_ENV['NS_PROJECT_ROOT'] = $repositoryRoot;
(new Dotenv())->bootEnv(__DIR__.'/.env');

$_SERVER['KERNEL_CLASS'] = NsUltimate\Integration\Backend\Kernel::class;
$_ENV['KERNEL_CLASS'] = NsUltimate\Integration\Backend\Kernel::class;
putenv('KERNEL_CLASS='.NsUltimate\Integration\Backend\Kernel::class);

return [
    'repository_root' => $repositoryRoot,
    'core_directory' => $coreDirectory,
    'modules' => $modules,
];
