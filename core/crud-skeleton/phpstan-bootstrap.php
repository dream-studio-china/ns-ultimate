<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

// Load environment for the dev container
(new Dotenv())->bootEnv(__DIR__ . '/.env');

// Static analysis only needs Doctrine metadata. Keep it runnable in a clean
// checkout where the application database URL has not been configured.
$_SERVER['DATABASE_URL'] ??= 'sqlite:///' . __DIR__ . '/var/phpstan.db';
$_ENV['DATABASE_URL'] ??= $_SERVER['DATABASE_URL'];

// Boot a minimal kernel to get the EntityManager
$kernel = new Kernel('dev', false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine.orm.entity_manager');
