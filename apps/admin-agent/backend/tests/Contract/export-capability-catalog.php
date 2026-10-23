<?php

declare(strict_types=1);

// Opt-in JS contract fixture: serialize the actual runtime catalog with the normal explicit
// adapter selection. This exporter performs no database lookup, domain write or network call.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require dirname(__DIR__, 5).'/integration/backend/tests/bootstrap.php';

use App\Identity\Entity\User;
use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilityCatalog;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

$kernel = new Kernel('test', true);
$kernel->boot();
try {
    $container = $kernel->getContainer()->get('test.service_container');
    // The catalog is account-filtered; a synthetic admin token makes the fixture independent of
    // an HTTP session without weakening the runtime endpoint's authenticated ROLE_ADMIN check.
    $user = (new User())->setRoles(['ROLE_ADMIN']);
    $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']));
    $catalog = $container->get(CapabilityCatalog::class);
    echo $catalog->encode($catalog->build()), "\n";
} finally {
    $kernel->shutdown();
}
