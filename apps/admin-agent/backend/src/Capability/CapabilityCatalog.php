<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Capability;

use NsUltimate\Apps\AdminAgent\Backend\Contract\BatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AgentMutationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchCreateAdapterRegistry;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Common\Service\TagWriteService;
use NsUltimate\Business\Counter\Service\CounterWriteService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Backend-authoritative, account-filtered serialization of the approved assistant capabilities.
 *
 * The catalog never reflects Doctrine metadata, OpenAPI operations or arbitrary domain fields.
 * Static descriptors are app policy; Tag and Counter descriptors are derived from business field
 * schemas and then reduced to the app allowlist.
 */
final class CapabilityCatalog
{
    public const SCHEMA_VERSION = 1;
    public const MAX_RESPONSE_BYTES = 131072;
    public const BATCH_MIN_ROWS = 1;
    public const BATCH_MAX_ROWS = 20;

    public function __construct(
        private readonly MutationAdapterRegistry $adapters,
        private readonly BatchCreateAdapterRegistry $batchAdapters,
        private readonly AdminConfigurationPolicy $configuration,
        private readonly ?TagWriteService $tagWriteService = null,
        private readonly ?CounterWriteService $counterWriteService = null,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $snapshot = $this->configuration->snapshot();
        if (($snapshot['available'] ?? false) !== true) {
            return $this->emptyCatalog();
        }

        $capabilities = [];
        foreach (CapabilitySchemas::ENTITY_ORDER as $entity) {
            $descriptor = $this->descriptor($entity);
            if ($descriptor === null) {
                continue;
            }
            try {
                $adapter = $this->adapters->get($entity);
            } catch (BadRequestHttpException) {
                continue;
            }
            $descriptor = $this->authorizedDescriptor($descriptor, $adapter, $snapshot);
            if ($descriptor !== null) {
                $capabilities[] = $descriptor;
            }
        }

        $content = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'capabilities' => $capabilities,
            'batchCapabilities' => $this->batchCapabilities($capabilities),
        ];

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'revision' => $this->revision($content),
            'capabilities' => $capabilities,
            'batchCapabilities' => $content['batchCapabilities'],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyCatalog(): array
    {
        $content = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'capabilities' => [],
            'batchCapabilities' => [],
        ];

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'revision' => $this->revision($content),
            'capabilities' => [],
            'batchCapabilities' => [],
        ];
    }

    /** @param array<string, mixed> $catalog */
    public function encode(array $catalog): string
    {
        $json = json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new \LengthException('The capability catalog exceeds the 128 KiB response bound.');
        }

        return $json;
    }

    /** @return array<string, mixed>|null */
    private function descriptor(string $entity): ?array
    {
        if ($entity === 'Counter') {
            return $this->businessDescriptor('Counter', $this->counterWriteService);
        }
        if ($entity === 'Tag') {
            return $this->businessDescriptor('Tag', $this->tagWriteService);
        }

        return CapabilitySchemas::forEntity($entity);
    }

    /** @return array<string, mixed>|null */
    private function businessDescriptor(string $entity, TagWriteService|CounterWriteService|null $service): ?array
    {
        if ($service === null) {
            // The pilot capability is unavailable rather than silently described from a second
            // handwritten business schema when its owning service is not installed.
            return null;
        }

        return CapabilityFieldPolicy::descriptor(
            $entity,
            static fn (string $action): array => $service::fieldSchema($action),
        );
    }

    /**
     * Keep only actions and fields present in the trusted configuration manifest and permitted by
     * this adapter for the current account. The catalog passes no target identifier and therefore
     * never loads or discloses a record.
     *
     * @param array<string, mixed> $descriptor
     * @param array{
     *     available: bool,
     *     revision: ?string,
     *     entities: array<string, array<string, mixed>>,
     *     semanticCapabilities: array<string, array<string, mixed>>
     * } $snapshot
     * @return array<string, mixed>|null
     */
    private function authorizedDescriptor(array $descriptor, MutationAdapter $adapter, array $snapshot): ?array
    {
        $entity = $descriptor['entity'] ?? null;
        if (!is_string($entity)) {
            return null;
        }
        $semantic = $snapshot['semanticCapabilities'][$entity] ?? null;
        $manifestEntity = $snapshot['entities'][$entity] ?? null;
        $manifestActions = null;
        if (is_array($manifestEntity)) {
            $manifestActions = $manifestEntity['actions'];
        } elseif (is_array($semantic)) {
            $manifestActions = [
                'update' => [
                    'configured' => true,
                    'fields' => $semantic['fields'],
                ],
            ];
        }
        if (!is_array($manifestActions)) {
            return null;
        }

        $hasDelete = in_array('delete', $descriptor['actions'], true);
        // Delete previews may legitimately describe fields that this account cannot write. Capture
        // the existing explicit app safe-preview policy before authorized write-field projection.
        $safeDeletePreviewFields = $descriptor['fields'];
        unset($descriptor['deletePreviewFields']);

        $actions = [];
        $actionFields = [];
        foreach ($descriptor['actions'] as $action) {
            if (!AgentMutationPolicy::allows($entity, $action)) {
                continue;
            }
            $manifestAction = $manifestActions[$action] ?? null;
            if (!is_array($manifestAction) || ($manifestAction['configured'] ?? false) !== true) {
                continue;
            }

            $selectedFields = null;
            if ($action !== 'delete') {
                $descriptorFields = $descriptor['actionFields'][$action] ?? null;
                if (!is_array($descriptorFields)) {
                    continue;
                }
                $configured = [];
                foreach ($manifestAction['fields'] as $field) {
                    $configured[$field['property']] = $field;
                }

                $selectedFields = [];
                $actionImpossible = false;
                foreach ($descriptorFields as $property => $rule) {
                    $required = $action === 'create'
                        ? (is_array($rule) && ($rule['requiredOnCreate'] ?? false) === true)
                        : (is_array($rule) && ($rule['requiredOnUpdate'] ?? false) === true);
                    $configuredField = $configured[$property] ?? null;
                    if (!is_array($configuredField)) {
                        if ($required) {
                            // A mandatory field removed from the effective form disables the action
                            // rather than silently dropping the field from a destructive write.
                            $actionImpossible = true;
                            break;
                        }
                        continue;
                    }

                    $basePath = $configuredField['path'] ?? $property;
                    $nestedPaths = self::stringList($configuredField['nestedPaths'] ?? []);
                    if ($nestedPaths === null) {
                        $actionImpossible = true;
                        break;
                    }
                    $narrowed = $rule;
                    if ($nestedPaths !== [] || is_array($semantic)) {
                        $narrowed = self::narrowFieldRule($rule, $basePath, $nestedPaths);
                        if ($narrowed === null) {
                            if ($required) {
                                $actionImpossible = true;
                                break;
                            }
                            continue;
                        }
                    }
                    $selectedFields[$property] = $narrowed;
                }
                if ($actionImpossible || $selectedFields === []) {
                    continue;
                }
            }

            try {
                $adapter->authorize($action, null);
            } catch (AccessDeniedException|BadRequestHttpException|NotFoundHttpException) {
                continue;
            }
            $actions[] = $action;
            if ($selectedFields !== null) {
                $actionFields[$action] = $selectedFields;
            }
        }

        if ($actions === []) {
            return null;
        }

        $descriptor['actions'] = $actions;
        $descriptor['actionFields'] = $actionFields === [] ? new \stdClass() : $actionFields;
        // fields is only the union of fields from actions this account may actually perform.
        // Never retain descriptor fields belonging to a denied action (for example create-only
        // Product.store when only update is authorized).
        $descriptor['fields'] = self::unionActionFields($actionFields);
        if ($hasDelete && in_array('delete', $actions, true)) {
            $descriptor['deletePreviewFields'] = $safeDeletePreviewFields === [] ? new \stdClass() : $safeDeletePreviewFields;
        }

        return $descriptor;
    }

    /**
     * @param array<mixed> $value
     * @return list<string>|null
     */
    private static function stringList(array $value): ?array
    {
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * Narrow a nested object rule to the exact configured leaf paths. A container
     * path never permits arbitrary children. Paired properties that are only
     * partially present make the field/action impossible.
     *
     * @param array<string, mixed> $rule
     * @param list<string> $nestedPaths
     * @return array<string, mixed>|null
     */
    private static function narrowFieldRule(array $rule, string $basePath, array $nestedPaths): ?array
    {
        if (!isset($rule['properties']) || !is_array($rule['properties'])) {
            return $rule;
        }
        if ($nestedPaths === []) {
            return null;
        }

        $properties = [];
        foreach ($rule['properties'] as $property => $child) {
            if (!is_string($property) || !is_array($child)) {
                continue;
            }
            $childPath = $basePath.'.'.$property;
            $exact = in_array($childPath, $nestedPaths, true);
            $descendant = false;
            foreach ($nestedPaths as $path) {
                if (str_starts_with($path, $childPath.'.')) {
                    $descendant = true;
                    break;
                }
            }
            if (!$exact && !$descendant) {
                continue;
            }
            if (isset($child['properties']) && is_array($child['properties'])) {
                $narrowed = self::narrowFieldRule($child, $childPath, $nestedPaths);
                if ($narrowed === null) {
                    continue;
                }
                $properties[$property] = $narrowed;
            } elseif ($exact) {
                $properties[$property] = $child;
            }
        }

        if ($properties === []) {
            return null;
        }
        if (isset($rule['required']) && is_array($rule['required'])) {
            foreach ($rule['required'] as $property) {
                if (!is_string($property) || !isset($properties[$property])) {
                    return null;
                }
            }
        }
        $rule['properties'] = $properties;
        $rule['additionalProperties'] = false;
        // The operation policy denies clearing the root when nested schema proof
        // exists, so do not advertise nullable=true for the same object rule.
        $rule['nullable'] = false;

        if (isset($rule['pairedProperties']) && is_array($rule['pairedProperties'])) {
            foreach ($rule['pairedProperties'] as $pair) {
                if (!is_array($pair) || $pair === []) {
                    return null;
                }
                $present = 0;
                foreach ($pair as $property) {
                    if (isset($properties[$property])) {
                        $present++;
                    }
                }
                if ($present !== 0 && $present !== count($pair)) {
                    return null;
                }
            }
        }

        return $rule;
    }

    /**
     * @param array<string, array<string, mixed>> $actionFields
     * @return array<string, mixed>|\stdClass
     */
    private static function unionActionFields(array $actionFields): array|\stdClass
    {
        $fields = [];
        foreach ($actionFields as $rules) {
            $fields = array_replace($fields, $rules);
        }

        return $fields === [] ? new \stdClass() : $fields;
    }

    /**
     * Batch descriptors reuse the selected capability's create policy so the client can compile
     * one validator for single and batch creation. The batch transport itself remains bounded to
     * BATCH_MIN_ROWS..BATCH_MAX_ROWS rows.
     *
     * @param list<array<string, mixed>> $capabilities
     * @return list<array<string, mixed>>
     */
    private function batchCapabilities(array $capabilities): array
    {
        $byEntity = array_column($capabilities, null, 'entity');
        $batch = [];
        foreach ($this->batchAdapters->all() as $entity => $adapter) {
            if (!$adapter instanceof BatchCreateAdapter) {
                throw new \LogicException('A selected batch adapter is not a BatchCreateAdapter.');
            }
            try {
                $adapter->authorize();
            } catch (AccessDeniedException|BadRequestHttpException|NotFoundHttpException) {
                continue;
            }
            $capability = $byEntity[$entity] ?? null;
            if (!is_array($capability) || !in_array('create', $capability['actions'], true)) {
                // A batch adapter must not extend the single-record capability boundary.
                continue;
            }
            $fields = $capability['actionFields']['create'] ?? null;
            if (!is_array($fields)) {
                continue;
            }
            $batch[] = [
                'entity' => $entity,
                'actions' => ['create'],
                'actionFields' => ['create' => $fields],
                'fields' => $fields,
            ];
        }

        $order = array_flip(CapabilitySchemas::ENTITY_ORDER);
        usort($batch, static function (array $left, array $right) use ($order): int {
            return ($order[$left['entity']] ?? PHP_INT_MAX) <=> ($order[$right['entity']] ?? PHP_INT_MAX)
                ?: strcmp($left['entity'], $right['entity']);
        });

        return $batch;
    }

    /** @param array<string, mixed> $content */
    private function revision(array $content): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($content),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
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
