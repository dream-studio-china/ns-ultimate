<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Capability;

use NsUltimate\Apps\AdminAgent\Backend\Contract\BatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
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
        private readonly ?TagWriteService $tagWriteService = null,
        private readonly ?CounterWriteService $counterWriteService = null,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
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
            $descriptor = $this->authorizedDescriptor($descriptor, $adapter);
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
     * Keep only actions this adapter permits for the current account. The catalog passes no target
     * identifier and therefore never loads or discloses a record.
     *
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>|null
     */
    private function authorizedDescriptor(array $descriptor, MutationAdapter $adapter): ?array
    {
        $hasDelete = in_array('delete', $descriptor['actions'], true);
        // Delete previews may legitimately describe fields that this account cannot write. Capture
        // the existing explicit app safe-preview policy before authorized write-field projection.
        $safeDeletePreviewFields = $descriptor['fields'];
        unset($descriptor['deletePreviewFields']);

        $actions = [];
        $actionFields = [];
        foreach ($descriptor['actions'] as $action) {
            try {
                $adapter->authorize($action, null);
            } catch (AccessDeniedException|BadRequestHttpException|NotFoundHttpException) {
                continue;
            }
            $actions[] = $action;
            if (isset($descriptor['actionFields'][$action])) {
                $actionFields[$action] = $descriptor['actionFields'][$action];
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
