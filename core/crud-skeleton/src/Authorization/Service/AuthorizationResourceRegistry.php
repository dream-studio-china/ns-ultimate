<?php

declare(strict_types=1);

namespace App\Authorization\Service;

final class AuthorizationResourceRegistry
{
    /**
     * @var array<string, array<string, list<string>>>
     */
    private array $resources;

    /**
     * @param array<string, array<string, list<string>>> $resources
     */
    public function __construct(array $resources = [])
    {
        if ($resources === []) {
            $resources = [
                'common:content' => [
                    'create' => ['title', 'body', 'category', 'tags', 'metadata'],
                    'update' => ['title', 'body', 'category', 'tags', 'metadata'],
                ],
            ];
        }
        $this->resources = $resources;
    }

    public static function default(): self
    {
        return new self([
            'common:content' => [
                'create' => ['title', 'body', 'category', 'tags', 'metadata'],
                'update' => ['title', 'body', 'category', 'tags', 'metadata'],
            ],
        ]);
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedFields(string $resource, string $action): ?array
    {
        return $this->resources[$resource][$action] ?? null;
    }

    public function hasResourceAction(string $resource, string $action): bool
    {
        return isset($this->resources[$resource][$action]);
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public function all(): array
    {
        return $this->resources;
    }

    /** @param list<string> $fields */
    public function assertValidFields(string $resource, string $action, array $fields): void
    {
        $allowed = $this->getAllowedFields($resource, $action);
        if ($allowed === null) {
            throw new \InvalidArgumentException(sprintf('Unknown resource action "%s:%s".', $resource, $action));
        }
        $invalid = array_diff($fields, $allowed);
        if ($invalid !== []) {
            throw new \InvalidArgumentException(sprintf('Invalid fields for "%s:%s": %s.', $resource, $action, implode(', ', $invalid)));
        }
    }
}
