<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Capability;

/**
 * App-owned allowlist for the Tag and Counter pilot capabilities.
 *
 * Business services own the reusable field rules. This policy owns which of those rules the
 * assistant may expose and which actions exist, so a newly added domain field stays denied until
 * it is explicitly listed here.
 */
final class CapabilityFieldPolicy
{
    /**
     * Bumped when the app changes how business field metadata is intersected or serialized.
     * It is intentionally independent of the public catalog schemaVersion.
     */
    public const FIELD_NORMALIZATION_VERSION = 1;

    /** @var array<string, list<string>> */
    private const ACTIONS = [
        'Counter' => ['create', 'update', 'delete'],
        'Tag' => ['create', 'update', 'delete'],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const FIELDS = [
        'Counter' => [
            'create' => ['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled'],
            'update' => ['code', 'name', 'capacity', 'sort', 'enabled'],
            'delete' => [],
        ],
        'Tag' => [
            'create' => ['name', 'slug', 'color'],
            'update' => ['name', 'slug', 'color'],
            'delete' => [],
        ],
    ];

    /**
     * requiredOnCreate belongs to the exported action contract, not to a shared field map. The
     * current business schemas reuse one mutable map for create and update, so serialize the
     * approved per-action value for this flag while keeping every other metadata value verbatim.
     *
     * @var array<string, array<string, array<string, bool>>>
     */
    private const REQUIRED_ON_CREATE = [
        'Counter' => [
            'create' => ['storeUuid' => true, 'code' => true, 'name' => true],
            'update' => ['code' => false, 'name' => false],
        ],
        'Tag' => [
            'create' => ['name' => true, 'slug' => true],
            'update' => ['name' => true, 'slug' => true],
        ],
    ];

    /**
     * @param callable(string): array<string, mixed> $schemaForAction
     * @return array<string, mixed>|null
     */
    public static function descriptor(string $entity, callable $schemaForAction): ?array
    {
        if (!isset(self::ACTIONS[$entity])) {
            throw new \InvalidArgumentException('No business field policy for '.$entity.'.');
        }

        $actionFields = [];
        $actions = [];
        foreach (self::ACTIONS[$entity] as $action) {
            if ($action === 'delete') {
                $actions[] = $action;
                continue;
            }
            $fields = self::intersect($schemaForAction($action), self::FIELDS[$entity][$action]);
            foreach ($fields as $field => $rule) {
                if (array_key_exists('requiredOnCreate', $rule)
                    && isset(self::REQUIRED_ON_CREATE[$entity][$action][$field])) {
                    $fields[$field]['requiredOnCreate'] = self::REQUIRED_ON_CREATE[$entity][$action][$field];
                }
            }
            if ($fields === []) {
                continue;
            }
            $actionFields[$action] = $fields;
            $actions[] = $action;
        }

        if ($actionFields === []) {
            return null;
        }

        $fields = [];
        foreach ($actionFields as $rules) {
            $fields = array_replace($fields, $rules);
        }

        return [
            'entity' => $entity,
            'actions' => $actions,
            'actionFields' => $actionFields,
            'fields' => $fields,
        ];
    }

    /**
     * Keep only explicit app-approved fields while preserving the business rule values and the
     * allowlist order. Unknown or newly added business fields are dropped by default.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $allowedFields
     * @return array<string, mixed>
     */
    public static function intersect(array $schema, array $allowedFields): array
    {
        if ($schema !== [] && array_is_list($schema)) {
            throw new \LogicException('A business field schema must be a field map, not a list.');
        }

        $fields = [];
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $schema)) {
                continue;
            }
            $rule = $schema[$field];
            if (!is_array($rule) || ($rule !== [] && array_is_list($rule))) {
                throw new \LogicException('A business field rule must be an object.');
            }
            $fields[$field] = $rule;
        }

        return $fields;
    }
}
