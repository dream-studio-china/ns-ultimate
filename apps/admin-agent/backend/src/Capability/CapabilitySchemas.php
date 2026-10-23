<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Capability;

/**
 * Explicit backend-only descriptors for the non-pilot capabilities.
 *
 * These arrays are the authoritative serialization source for the capability catalog. They are
 * intentionally hand-authored policy, not reflective Doctrine or OpenAPI metadata: only fields
 * already approved for assistant mutations are described here.
 */
final class CapabilitySchemas
{
    public const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';

    public const PRINTING_DESCRIPTION = 'FUTURE automatic order printing configuration only: this changes stored settings for orders processed after the change. It never prints now, never creates a print job, and never contacts a printer provider.';

    /** Stable response order; Tag and Counter are inserted from the business field services. */
    public const ENTITY_ORDER = [
        'Content',
        'Counter',
        'Store',
        'Category',
        'ProductCategory',
        'Tag',
        'Product',
        'Specification',
        'StorePrinting',
        'ProductPrinting',
    ];

    /** @return array<string, mixed>|null */
    public static function forEntity(string $entity): ?array
    {
        return match ($entity) {
            'Content' => self::content(),
            'Store' => self::store(),
            'Category' => self::category(),
            'ProductCategory' => self::productCategory(),
            'Product' => self::product(),
            'Specification' => self::specification(),
            'StorePrinting' => self::storePrinting(),
            'ProductPrinting' => self::productPrinting(),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function content(): array
    {
        $fields = self::contentFields();

        return [
            'entity' => 'Content',
            'actions' => ['create', 'update', 'delete'],
            'actionFields' => ['create' => $fields, 'update' => $fields],
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private static function store(): array
    {
        $fields = [
            'name' => self::string(255, false, true, ['trimForValidation' => true]),
            'contact' => self::objectField(self::contactFields()),
            'address' => self::objectField(self::addressFields(), ['pairedProperties' => [['latitude', 'longitude']]]),
        ];

        return [
            'entity' => 'Store',
            'actions' => ['update'],
            'actionFields' => ['update' => $fields],
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private static function category(): array
    {
        $fields = self::categoryFields();

        return [
            'entity' => 'Category',
            'actions' => ['create', 'update', 'delete'],
            'actionFields' => ['create' => $fields, 'update' => $fields],
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private static function productCategory(): array
    {
        $create = self::categoryFields(100, true) + [
            'store' => self::recordReference() + [
                'default' => null,
                'description' => 'Null or omitted means global; otherwise select an existing store ID or UUID.',
            ],
        ];
        $update = self::categoryFields(100, true);

        return [
            'entity' => 'ProductCategory',
            'actions' => ['create', 'update', 'delete'],
            'actionFields' => ['create' => $create, 'update' => $update],
            'fields' => array_replace($create, $update),
        ];
    }

    /** @return array<string, mixed> */
    private static function product(): array
    {
        $create = self::productFields(true);
        $update = self::productFields(false);

        return [
            'entity' => 'Product',
            'actions' => ['create', 'update'],
            'actionFields' => ['create' => $create, 'update' => $update],
            'fields' => array_replace($create, $update),
        ];
    }

    /** @return array<string, mixed> */
    private static function specification(): array
    {
        $create = [
            'productId' => [
                'type' => 'positive-id',
                'minimum' => 1,
                'maximum' => 2147483647,
                'requiredOnCreate' => true,
                'immutableScope' => true,
            ],
            'name' => self::string(255, true, true, ['trimExact' => true, 'disallowLessThan' => true]),
            'price' => self::integer(0, 2147483647, ['requiredOnCreate' => true]),
            'status' => self::enum(['active', 'inactive']),
            'sort' => self::integer(-2147483648, 2147483647),
        ];
        $update = [
            'productId' => [
                'type' => 'positive-id',
                'minimum' => 1,
                'maximum' => 2147483647,
                'requiredOnUpdate' => true,
                'immutableScope' => true,
            ],
            'name' => self::string(255, false, true, ['trimExact' => true, 'disallowLessThan' => true]),
            'price' => self::integer(0, 2147483647),
            'status' => self::enum(['active', 'inactive']),
            'sort' => self::integer(-2147483648, 2147483647),
        ];

        return [
            'entity' => 'Specification',
            'actions' => ['create', 'update'],
            'actionFields' => ['create' => $create, 'update' => $update],
            'fields' => array_replace($create, $update),
        ];
    }

    /** @return array<string, mixed> */
    private static function storePrinting(): array
    {
        $fields = [];
        foreach (['order', 'perItem', 'filteredList', 'fullList', 'settlement'] as $caseKey) {
            $fields[$caseKey] = [
                'type' => 'object',
                'nullable' => false,
                'additionalProperties' => false,
                'properties' => self::printingCaseRules(),
                'description' => self::PRINTING_DESCRIPTION.' Case: '.$caseKey.' for the exact stored Store.',
            ];
        }

        return [
            'entity' => 'StorePrinting',
            'description' => 'Update the five fixed automatic order-printing cases on the exact stored Store identified by numeric ID or UUID. Only update is supported; settings roots and unrelated settings fields are not accepted.',
            'actions' => ['update'],
            'actionFields' => ['update' => $fields],
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private static function productPrinting(): array
    {
        $fields = [
            'kitchenPrinterEnable' => [
                'type' => 'boolean',
                'description' => 'FUTURE automatic kitchen-list printing for this exact stored Product only. '.self::PRINTING_DESCRIPTION,
            ],
        ];

        return [
            'entity' => 'ProductPrinting',
            'description' => 'Update only kitchenPrinterEnable on the exact stored Product identified by numeric ID or UUID. Only update is supported; arbitrary settings, metadata, and other fields are not accepted.',
            'actions' => ['update'],
            'actionFields' => ['update' => $fields],
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private static function contentFields(): array
    {
        return [
            'title' => self::string(255, true),
            'body' => self::nullableString(20000),
            'category' => ['type' => 'positive-id', 'nullable' => true],
            'tags' => ['type' => 'positive-id-list', 'maxItems' => 50],
            'metadata' => [
                'type' => 'object',
                'nullable' => true,
                'additionalProperties' => false,
                'properties' => [
                    'featured' => ['type' => 'boolean'],
                    'layout' => self::enum(['article', 'landing']),
                ],
            ],
            'sortOrder' => self::integer(-2147483648, 2147483647),
        ];
    }

    /** @return array<string, mixed> */
    private static function contactFields(): array
    {
        return [
            'phone' => self::string(20, false, true, ['pattern' => '^(\+?[0-9\- ]{7,20}|1[3-9][0-9]{9})$']),
            'managerPhone' => self::string(20, false, true, ['pattern' => '^(\+?[0-9\- ]{7,20}|1[3-9][0-9]{9})$']),
            'email' => self::string(255, false, true, ['format' => 'email']),
            'managerEmail' => self::string(255, false, true, ['format' => 'email']),
            'managerName' => self::string(40, false, true, ['minLength' => 1]),
            'managerUserUuid' => self::string(36, false, true, ['pattern' => self::UUID_PATTERN, 'patternFlags' => 'i']),
            'wechat' => self::string(64),
            'serviceHours' => self::string(100),
            'subTitle' => self::string(100, false, true, ['minLength' => 1]),
            'tags' => [
                'type' => 'string-list',
                'maxItems' => 20,
                'minItemLength' => 1,
                'maxItemLength' => 30,
                'uniqueItems' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function addressFields(): array
    {
        return [
            'province' => self::string(40, false, true, ['minLength' => 1]),
            'city' => self::string(40, false, true, ['minLength' => 1]),
            'district' => self::string(40, false, true, ['minLength' => 1]),
            'street' => self::string(100, false, true, ['minLength' => 1]),
            'detail' => self::string(255, false, true, ['minLength' => 1]),
            'building' => self::string(100),
            'floor' => self::string(40),
            'postalCode' => self::string(20, false, true, ['pattern' => '^[0-9A-Za-z\- ]{3,20}$']),
            'formattedAddress' => self::string(255),
            'latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
            'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
            'geohash' => self::string(12, false, true, ['pattern' => '^[0-9bcdefghjkmnpqrstuvwxyz]{1,12}$']),
            'poiId' => self::string(64),
        ];
    }

    /** @return array<string, mixed> */
    private static function categoryFields(int $slugMaxLength = 255, bool $productCategory = false): array
    {
        return [
            'name' => self::string(255, true),
            'slug' => self::string($slugMaxLength, true),
            'description' => self::nullableString(65535),
            'parent' => self::recordReference($productCategory ? ['mustMatchStoreScope' => true] : []),
            'sortOrder' => self::integer(),
            'enabled' => ['type' => 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private static function productFields(bool $create): array
    {
        $fields = [
            'name' => self::string(255, $create, true, ['trimExact' => true, 'disallowLessThan' => true]),
            'description' => self::nullableString(65535, ['disallowHtmlTags' => true]),
            'status' => self::enum(['active', 'inactive']),
        ];
        if ($create) {
            $fields['store'] = [
                'type' => 'uuid',
                'pattern' => self::UUID_PATTERN,
                'patternFlags' => 'i',
                'nullable' => true,
                'default' => null,
                'description' => 'Omit or pass null for a global product; otherwise pass an existing store UUID.',
            ];
        }
        $fields['category'] = [
            'type' => 'positive-id',
            'nullable' => true,
            'mustBeEnabled' => true,
            'mustMatchProductStore' => true,
        ];
        $fields['sortOrder'] = self::integer(-2147483648, 2147483647);
        $fields['recommendationLevel'] = self::integer(-2147483648, 2147483647);
        $fields['isSoldout'] = ['type' => 'boolean'];

        return $fields;
    }

    /** @return array<string, mixed> */
    private static function printingCaseRules(): array
    {
        return [
            'enabled' => [
                'type' => 'boolean',
                'description' => 'Whether this fixed automatic order-printing case is enabled for FUTURE orders only.',
            ],
            'deviceUuid' => [
                'type' => 'uuid',
                'pattern' => self::UUID_PATTERN,
                'patternFlags' => 'i',
                'nullable' => true,
                'description' => 'Canonical UUID of an existing printer device, or null to clear it. Initial partial patches may omit it so the backend merges the stored reference; an explicit null with enabled true is rejected. An effective restored case requires it, non-null, when enabled is true.',
            ],
            'templateUuid' => [
                'type' => 'uuid',
                'pattern' => self::UUID_PATTERN,
                'patternFlags' => 'i',
                'nullable' => true,
                'description' => 'Canonical UUID of an existing printer template, or null to clear it. Initial partial patches may omit it so the backend merges the stored reference; an explicit null with enabled true is rejected. An effective restored case requires it, non-null, when enabled is true.',
            ],
            'copies' => self::integer(1, 100, [
                'description' => 'Copies for this case from 1 through 100; the backend effective configuration defaults to 1.',
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function recordReference(array $extra = []): array
    {
        return [
            'type' => 'union',
            'nullable' => true,
            'anyOf' => [
                ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647],
                ['type' => 'numeric-id-string', 'minimum' => 1, 'maximum' => 2147483647],
                ['type' => 'string', 'format' => 'uuid', 'pattern' => self::UUID_PATTERN, 'patternFlags' => 'i'],
            ],
        ] + $extra;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function string(int $maxLength, bool $requiredOnCreate = false, ?bool $nonEmpty = null, array $extra = []): array
    {
        return [
            'type' => 'string',
            'maxLength' => $maxLength,
            'requiredOnCreate' => $requiredOnCreate,
            'nonEmpty' => $nonEmpty ?? $requiredOnCreate,
        ] + $extra;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function nullableString(int $maxBytes, array $extra = []): array
    {
        return ['type' => 'utf8-string', 'maxBytes' => $maxBytes, 'nullable' => true] + $extra;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function integer(int $minimum = 0, int $maximum = 2147483647, array $extra = []): array
    {
        return ['type' => 'integer', 'minimum' => $minimum, 'maximum' => $maximum] + $extra;
    }

    /**
     * @param list<string> $values
     * @return array<string, mixed>
     */
    private static function enum(array $values): array
    {
        return ['type' => 'enum', 'values' => $values];
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private static function objectField(array $properties, array $constraints = []): array
    {
        return [
            'type' => 'object',
            'nullable' => true,
            'additionalProperties' => false,
            'properties' => $properties,
        ] + $constraints;
    }
}
