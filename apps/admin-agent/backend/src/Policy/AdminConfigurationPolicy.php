<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Policy;

use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Trusted, server-side negative gate for assistant mutations.
 *
 * The artifact is generated from the composed crud-admin configuration and is
 * loaded from a private project path, never from a request. Configuration
 * membership can only remove capabilities; it never grants authorization or
 * replaces existing business/agent validation.
 *
 * v1 list-shaped manifest consumed here:
 *
 *   {
 *     "schemaVersion": 1,
 *     "revision": "<sha256>",
 *     "source": "integration/admin/config.ts",
 *     "entities": [{
 *       "key": "Store",
 *       "name": "Store",
 *       "actions": {
 *         "create": {"configured": true, "fields": [
 *           {"property": "settings", "nestedPaths": ["settings.printing.order.enabled"]}
 *         ]},
 *         "update": {...},
 *         "delete": {"configured": true, "fields": []}
 *       }
 *     }],
 *     "semanticCapabilities": [{
 *       "name": "StorePrinting",
 *       "entity": "Store",
 *       "actions": ["update"],
 *       "fields": [{
 *         "property": "order",
 *         "path": "settings.printing.order",
 *         "nestedPaths": ["settings.printing.order.enabled"]
 *       }]
 *     }]
 *   }
 *
 * The revision is the SHA-256 of the canonical JSON payload without `revision`,
 * including `semanticCapabilities`. Missing, unreadable, oversized, tampered or
 * malformed artifacts fail closed: the catalog becomes empty and writes are
 * rejected.
 */
final class AdminConfigurationPolicy
{
    public const SCHEMA_VERSION = 1;
    public const SOURCE = 'integration/admin/config.ts';
    public const DEFAULT_MAX_BYTES = 1048576;
    public const MAX_ENTITIES = 64;
    public const MAX_FIELDS_PER_ACTION = 256;
    public const MAX_SEMANTIC_CAPABILITIES = 64;
    public const MAX_NESTED_PATHS = 128;
    public const MAX_PATH_SEGMENTS = 8;
    public const MAX_PATH_SEGMENT_LENGTH = 64;
    public const MAX_JSON_DEPTH = 24;
    public const ACTIONS = ['create', 'update', 'delete'];
    public const SEMANTIC_ACTIONS = ['update'];

    private const SAFE_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D';
    private const PATH_SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';
    private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';
    private const UNSAFE_NAMES = ['__proto__', 'prototype', 'constructor'];
    private const PREPARE_REJECTED = 'This entity, action or field is not enabled by the current admin configuration.';
    private const MUTATION_REVOKED = 'The admin configuration no longer permits this operation. Prepare a new preview.';

    private readonly ?string $manifestPath;

    public function __construct(
        ?string $manifestPath = null,
        #[Autowire('%kernel.project_dir%')] ?string $projectDir = null,
    ) {
        // Explicit manual paths (tests, tools, exporters) win. The composed
        // container injects only projectDir, so server-side env/deployment
        // defaults can select the artifact without editing services.yaml.
        $environmentPath = getenv('ADMIN_AGENT_MANIFEST_PATH');
        $this->manifestPath = $manifestPath
            ?? (is_string($environmentPath) && trim($environmentPath) !== ''
                ? trim($environmentPath)
                : ($projectDir !== null ? $projectDir.'/../../var/admin-agent/admin-manifest.json' : null));
    }

    /**
     * Read the current artifact. The file is deliberately not cached: a
     * long-running worker must observe the latest published revision.
     *
     * @return array{
     *     available: bool,
     *     revision: ?string,
     *     entities: array<string, array<string, mixed>>,
     *     semanticCapabilities: array<string, array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        $unavailable = ['available' => false, 'revision' => null, 'entities' => [], 'semanticCapabilities' => []];
        if ($this->manifestPath === null || trim($this->manifestPath) === '') {
            return $unavailable;
        }

        $raw = $this->readArtifact($this->manifestPath);
        if ($raw === null) {
            return $unavailable;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $unavailable;
        }
        if (!is_array($decoded) || array_is_list($decoded) || self::depth($decoded) > self::MAX_JSON_DEPTH) {
            return $unavailable;
        }
        if (!self::hasExactKeys($decoded, ['schemaVersion', 'revision', 'source', 'entities', 'semanticCapabilities'])) {
            return $unavailable;
        }
        if (($decoded['schemaVersion'] ?? null) !== self::SCHEMA_VERSION
            || ($decoded['source'] ?? null) !== self::SOURCE
            || !is_string($decoded['revision'] ?? null)
            || !preg_match(self::HASH_PATTERN, $decoded['revision'])) {
            return $unavailable;
        }

        $entities = $decoded['entities'] ?? null;
        if (!is_array($entities) || !array_is_list($entities) || count($entities) > self::MAX_ENTITIES) {
            return $unavailable;
        }

        $normalized = [];
        $keys = [];
        foreach ($entities as $entity) {
            if (!is_array($entity) || array_is_list($entity)
                || !self::hasExactKeys($entity, ['key', 'name', 'actions'])
                || !is_string($entity['key'] ?? null)
                || !is_string($entity['name'] ?? null)
                || !self::safeName($entity['key'])
                || !self::safeName($entity['name'])
                || isset($normalized[$entity['name']])
                || isset($keys[$entity['key']])
                || !is_array($entity['actions'] ?? null)
                || array_is_list($entity['actions'])
                || !self::hasExactKeys($entity['actions'], self::ACTIONS)) {
                return $unavailable;
            }

            $actions = [];
            foreach (self::ACTIONS as $actionName) {
                $action = $entity['actions'][$actionName] ?? null;
                if (!is_array($action) || array_is_list($action)
                    || !self::hasExactKeys($action, ['configured', 'fields'])
                    || !is_bool($action['configured'] ?? null)
                    || !is_array($action['fields'] ?? null)
                    || !array_is_list($action['fields'])
                    || count($action['fields']) > self::MAX_FIELDS_PER_ACTION) {
                    return $unavailable;
                }

                $fields = [];
                $seenFields = [];
                foreach ($action['fields'] as $field) {
                    if (!is_array($field) || array_is_list($field)
                        || !self::hasFieldKeys($field)
                        || !is_string($field['property'] ?? null)
                        || !self::safeName($field['property'])
                        || isset($seenFields[$field['property']])) {
                        return $unavailable;
                    }
                    $nestedPaths = self::normalizeNestedPaths($field, $field['property']);
                    if ($nestedPaths === null) {
                        return $unavailable;
                    }
                    $seenFields[$field['property']] = true;
                    $fields[] = ['property' => $field['property'], 'nestedPaths' => $nestedPaths];
                }
                if ($actionName === 'delete' && $fields !== []) {
                    return $unavailable;
                }
                $actions[$actionName] = ['configured' => $action['configured'], 'fields' => $fields];
            }

            $normalized[$entity['name']] = [
                'key' => $entity['key'],
                'name' => $entity['name'],
                'actions' => $actions,
            ];
            $keys[$entity['key']] = true;
        }

        $semanticCapabilities = $decoded['semanticCapabilities'] ?? null;
        if (!is_array($semanticCapabilities)
            || !array_is_list($semanticCapabilities)
            || count($semanticCapabilities) > self::MAX_SEMANTIC_CAPABILITIES) {
            return $unavailable;
        }

        $semantic = [];
        foreach ($semanticCapabilities as $capability) {
            if (!is_array($capability) || array_is_list($capability)
                || !self::hasExactKeys($capability, ['name', 'entity', 'actions', 'fields'])
                || !is_string($capability['name'] ?? null)
                || !is_string($capability['entity'] ?? null)
                || !self::safeName($capability['name'])
                || !self::safeName($capability['entity'])
                || !is_array($capability['actions'] ?? null)
                || !array_is_list($capability['actions'])
                || $capability['actions'] !== self::SEMANTIC_ACTIONS
                || !is_array($capability['fields'] ?? null)
                || !array_is_list($capability['fields'])
                || count($capability['fields']) > self::MAX_FIELDS_PER_ACTION) {
                return $unavailable;
            }

            $fields = [];
            $seenFields = [];
            foreach ($capability['fields'] as $field) {
                if (!is_array($field) || array_is_list($field)
                    || !self::hasSemanticFieldKeys($field)
                    || !is_string($field['property'] ?? null)
                    || !is_string($field['path'] ?? null)
                    || !self::safeName($field['property'])
                    || !self::safePath($field['path'])
                    || isset($seenFields[$field['property']])) {
                    return $unavailable;
                }
                $nestedPaths = self::normalizeNestedPaths($field, $field['path']);
                if ($nestedPaths === null) {
                    return $unavailable;
                }
                $seenFields[$field['property']] = true;
                $fields[] = [
                    'property' => $field['property'],
                    'path' => $field['path'],
                    'nestedPaths' => $nestedPaths,
                ];
            }

            // A semantic capability is accepted only while its source entity
            // still exposes the update root field and the exact configured path.
            // A forged or stale alias is dropped rather than broadening writes.
            $source = $normalized[$capability['entity']] ?? null;
            if (!is_array($source)) {
                continue;
            }
            $update = $source['actions']['update'] ?? null;
            if (!is_array($update) || $update['configured'] !== true) {
                continue;
            }
            $sourceFields = [];
            foreach ($update['fields'] as $sourceField) {
                $sourceFields[$sourceField['property']] = $sourceField;
            }

            $valid = true;
            $normalizedFields = [];
            foreach ($fields as $field) {
                $segments = explode('.', $field['path']);
                $root = $segments[0] ?? '';
                $rootField = $sourceFields[$root] ?? null;
                if (!is_array($rootField)) {
                    $valid = false;
                    break;
                }
                $rootPaths = $rootField['nestedPaths'];
                if (!self::pathIsCovered($field['path'], $rootPaths)) {
                    $valid = false;
                    break;
                }
                foreach ($field['nestedPaths'] as $nestedPath) {
                    if (!str_starts_with($nestedPath, $field['path'].'.')
                        || !in_array($nestedPath, $rootPaths, true)) {
                        $valid = false;
                        break 2;
                    }
                }
                $normalizedFields[$field['property']] = $field;
            }
            if (!$valid || $normalizedFields === [] || isset($semantic[$capability['name']])) {
                continue;
            }
            $semantic[$capability['name']] = [
                'name' => $capability['name'],
                'entity' => $capability['entity'],
                'actions' => self::SEMANTIC_ACTIONS,
                'fields' => array_values($normalizedFields),
            ];
        }

        $expected = self::revisionOf([
            'schemaVersion' => self::SCHEMA_VERSION,
            'source' => self::SOURCE,
            'entities' => $entities,
            'semanticCapabilities' => $semanticCapabilities,
        ]);
        if (!hash_equals($expected, strtolower($decoded['revision']))) {
            return $unavailable;
        }

        return [
            'available' => true,
            'revision' => strtolower($decoded['revision']),
            'entities' => $normalized,
            'semanticCapabilities' => $semantic,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->snapshot()['available'];
    }

    public function revision(): ?string
    {
        return $this->snapshot()['revision'];
    }

    /**
     * Enforce entity/action/field membership before any adapter preparation.
     *
     * @param array<string, mixed> $changes
     * @param array{
     *     available: bool,
     *     revision: ?string,
     *     entities: array<string, array<string, mixed>>,
     *     semanticCapabilities: array<string, array<string, mixed>>
     * }|null $snapshot
     */
    public function assertPrepareAllowed(string $entity, string $action, array $changes, ?array $snapshot = null): void
    {
        if (!$this->allows($entity, $action, $changes, $snapshot)) {
            throw new BadRequestHttpException(self::PREPARE_REJECTED);
        }
    }

    /**
     * Enforce current configuration membership against a previously stored
     * proposal. Revocation is a definitive proposal conflict, not an unknown
     * write outcome.
     *
     * @param array<string, mixed> $changes
     * @param array{
     *     available: bool,
     *     revision: ?string,
     *     entities: array<string, array<string, mixed>>,
     *     semanticCapabilities: array<string, array<string, mixed>>
     * }|null $snapshot
     */
    public function assertMutationAllowed(string $entity, string $action, array $changes, ?array $snapshot = null): void
    {
        if (!$this->allows($entity, $action, $changes, $snapshot)) {
            throw new ProposalConflictException(self::MUTATION_REVOKED);
        }
    }

    /**
     * @param array<string, mixed> $changes
     * @param array{
     *     available: bool,
     *     revision: ?string,
     *     entities: array<string, array<string, mixed>>,
     *     semanticCapabilities: array<string, array<string, mixed>>
     * }|null $snapshot
     */
    public function allows(string $entity, string $action, array $changes, ?array $snapshot = null): bool
    {
        if (!in_array($action, self::ACTIONS, true) || !self::safeName($entity)) {
            return false;
        }

        $snapshot ??= $this->snapshot();
        if (($snapshot['available'] ?? false) !== true) {
            return false;
        }

        $entry = $snapshot['entities'][$entity] ?? null;
        if (is_array($entry)) {
            $actionEntry = $entry['actions'][$action] ?? null;
            if (!is_array($actionEntry) || ($actionEntry['configured'] ?? false) !== true) {
                return false;
            }
            if ($action === 'delete') {
                return $changes === [];
            }

            $fields = [];
            foreach ($actionEntry['fields'] as $field) {
                $fields[$field['property']] = $field;
            }
            foreach ($changes as $property => $value) {
                if (!is_string($property) || !isset($fields[$property])) {
                    return false;
                }
                $field = $fields[$property];
                if (!self::valueAllowed($value, $field['property'], $field['nestedPaths'], true)) {
                    return false;
                }
            }

            return true;
        }

        $semantic = $snapshot['semanticCapabilities'][$entity] ?? null;
        if (!is_array($semantic) || !in_array($action, $semantic['actions'], true)) {
            return false;
        }
        $fields = [];
        foreach ($semantic['fields'] as $field) {
            $fields[$field['property']] = $field;
        }
        foreach ($changes as $property => $value) {
            if (!is_string($property) || !isset($fields[$property])) {
                return false;
            }
            $field = $fields[$property];
            if (!self::valueAllowed($value, $field['path'], $field['nestedPaths'], false)) {
                return false;
            }
        }

        return true;
    }

    private function readArtifact(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }
            $stat = fstat($handle);
            if (is_array($stat) && isset($stat['size']) && $stat['size'] > self::DEFAULT_MAX_BYTES) {
                return null;
            }
            $raw = stream_get_contents($handle, self::DEFAULT_MAX_BYTES + 1);
            if (!is_string($raw) || $raw === '' || strlen($raw) > self::DEFAULT_MAX_BYTES) {
                return null;
            }

            return $raw;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        $wanted = $expected;
        sort($wanted, SORT_STRING);

        return $actual === $wanted;
    }

    /** @param array<string, mixed> $field */
    private static function hasFieldKeys(array $field): bool
    {
        return self::hasExactKeys($field, ['property'])
            || self::hasExactKeys($field, ['property', 'nestedPaths']);
    }

    /** @param array<string, mixed> $field */
    private static function hasSemanticFieldKeys(array $field): bool
    {
        return self::hasExactKeys($field, ['property', 'path'])
            || self::hasExactKeys($field, ['property', 'path', 'nestedPaths']);
    }

    /**
     * Nested paths are absolute, property-qualified descendants. The producer
     * omits the key entirely when no schema proof exists; an explicit empty list
     * is rejected as malformed because it is indistinguishable from a known
     * closed-empty schema at runtime.
     *
     * @param array<string, mixed> $field
     * @return list<string>|null
     */
    private static function normalizeNestedPaths(array $field, string $prefix): ?array
    {
        if (!array_key_exists('nestedPaths', $field)) {
            return [];
        }
        $paths = $field['nestedPaths'];
        if (!is_array($paths)
            || !array_is_list($paths)
            || $paths === []
            || count($paths) > self::MAX_NESTED_PATHS) {
            return null;
        }
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path)
                || !self::safePath($path)
                || ($path !== $prefix && !str_starts_with($path, $prefix.'.'))
                || in_array($path, $normalized, true)) {
                return null;
            }
            $normalized[] = $path;
        }

        return $normalized;
    }

    private static function safeName(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::SAFE_NAME, $value) === 1
            && !in_array($value, self::UNSAFE_NAMES, true);
    }

    private static function safePath(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        $segments = explode('.', $value);
        if (count($segments) > self::MAX_PATH_SEGMENTS) {
            return false;
        }
        foreach ($segments as $segment) {
            if (strlen($segment) > self::MAX_PATH_SEGMENT_LENGTH
                || preg_match(self::PATH_SEGMENT, $segment) !== 1
                || in_array($segment, self::UNSAFE_NAMES, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $paths */
    private static function pathIsCovered(string $path, array $paths): bool
    {
        foreach ($paths as $candidate) {
            if ($candidate === $path || str_starts_with($candidate, $path.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths absolute configured paths
     */
    private static function valueAllowed(mixed $value, string $basePath, array $paths, bool $allowRootWithoutProof): bool
    {
        if ($paths === []) {
            // The root property is configured but the projection has no nested
            // schema proof. Only the root membership is granted here; existing
            // server rules narrow the value. Semantic aliases are stricter.
            return $allowRootWithoutProof || !is_array($value) || $value === [];
        }
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $child) {
            if (!is_string($key) || !self::safeName($key)) {
                return false;
            }
            $full = $basePath.'.'.$key;
            $exact = in_array($full, $paths, true);
            $descendant = false;
            foreach ($paths as $path) {
                if (str_starts_with($path, $full.'.')) {
                    $descendant = true;
                    break;
                }
            }
            if (!$exact && !$descendant) {
                return false;
            }
            if ($descendant) {
                if (!self::valueAllowed($child, $full, $paths, false)) {
                    return false;
                }
            } elseif (is_array($child) && $child !== []) {
                // An exact leaf does not imply arbitrary children.
                return false;
            }
        }

        return true;
    }

    private static function depth(mixed $value, int $depth = 1): int
    {
        if (!is_array($value)) {
            return $depth;
        }
        $maximum = $depth;
        foreach ($value as $item) {
            $maximum = max($maximum, self::depth($item, $depth + 1));
        }

        return $maximum;
    }

    /** @param array<string, mixed> $payload */
    private static function revisionOf(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                self::canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
