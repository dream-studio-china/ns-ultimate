<?php

declare(strict_types=1);

// Test-only legacy safety fixture exporter.
//
// The production exporter remains bound to the actual generated configuration
// manifest. This exporter explicitly installs the synthetic legacy manifest so
// the pre-gate all-ten safety fixture can be checked against the real service
// pipeline without exposing a production CLI flag or environment override.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require dirname(__DIR__, 5).'/integration/backend/tests/bootstrap.php';
require_once dirname(__DIR__).'/Integration/Support/AdminConfigurationTestManifest.php';

use App\Identity\Entity\User;
use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilityCatalog;
use NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

$supportManifest = AdminConfigurationTestManifest::install();
$legacyManifest = legacyManifestPath($supportManifest);
$kernel = new Kernel('test', true);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    // Match the actual exporter's synthetic admin session without weakening the
    // runtime endpoint's authenticated ROLE_ADMIN check.
    $user = (new User())->setRoles(['ROLE_ADMIN']);
    $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']));
    $catalog = $container->get(CapabilityCatalog::class);
    echo $catalog->encode($catalog->build()), "\n";
} finally {
    $kernel->shutdown();
    @unlink($legacyManifest);
    AdminConfigurationTestManifest::restore($supportManifest);
}

/**
 * Remove semantic-capability names from the entity list so the real catalog
 * gate uses its semantic branch. The synthetic builder currently emits both an
 * entity and a semantic entry for StorePrinting/ProductPrinting; retaining both
 * would make the entity branch win and hide the legitimate legacy descriptor.
 *
 * @return string
 */
function legacyManifestPath(string $supportManifest): string
{
    $raw = file_get_contents($supportManifest);
    if (!is_string($raw)) {
        throw new RuntimeException('The synthetic legacy manifest could not be read.');
    }
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $semanticNames = [];
    foreach ($manifest['semanticCapabilities'] ?? [] as $semantic) {
        if (is_array($semantic) && is_string($semantic['name'] ?? null)) {
            $semanticNames[] = $semantic['name'];
        }
    }
    $manifest['entities'] = array_values(array_filter(
        $manifest['entities'] ?? [],
        static fn (mixed $entity): bool => !is_array($entity) || !in_array($entity['name'] ?? null, $semanticNames, true),
    ));
    unset($manifest['revision']);
    $manifest['revision'] = hash(
        'sha256',
        json_encode(canonicalize($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    );

    $file = sys_get_temp_dir().'/admin-agent-legacy-export-'.bin2hex(random_bytes(8)).'.json';
    file_put_contents($file, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($file, 0600);
    putenv('ADMIN_AGENT_MANIFEST_PATH='.$file);
    $_ENV['ADMIN_AGENT_MANIFEST_PATH'] = $file;
    $_SERVER['ADMIN_AGENT_MANIFEST_PATH'] = $file;

    return $file;
}

/** @return mixed */
function canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('canonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = canonicalize($item);
    return $value;
}
