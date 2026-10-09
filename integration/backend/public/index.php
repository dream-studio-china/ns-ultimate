<?php

declare(strict_types=1);

use NsUltimate\Integration\Backend\Kernel;
use Symfony\Component\HttpFoundation\Request;

$application = require dirname(__DIR__).'/bootstrap.php';
chdir($application['core_directory']);

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) ($_SERVER['APP_DEBUG'] ?? false));
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
