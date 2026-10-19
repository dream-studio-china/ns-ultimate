<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Validator;

use Symfony\Component\Validator\Constraint;

/** Validates a mapped PHP value against a business-owned JSON Schema. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class JsonSchema extends Constraint
{
    public string $message = 'This value does not match JSON Schema "{{ schema }}": {{ detail }}';

    public function __construct(
        public string $schema,
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
        $this->message = $message ?? $this->message;
    }
}
