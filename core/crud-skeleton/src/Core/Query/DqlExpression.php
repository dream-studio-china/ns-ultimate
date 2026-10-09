<?php

declare(strict_types=1);

namespace App\Core\Query;

use App\Core\Utils\UUID;

/**
 * Server-owned row-level DQL scope.
 *
 * Only PHP code may construct this object. It is never created from HTTP
 * input, database rows, or Access-managed data.
 */
final class DqlExpression
{
    /**
     * @param string $expression Expression source, e.g. 'entity.getUser() == user'
     * @param array<string, mixed> $values Bound variables, e.g. ['user' => $user]
     * @param array<string, mixed> $criteria Internal mixin-added criteria, e.g. ['id' => 42]
     * @param object|null $context Internal controller context for `this` binding
     */
    public function __construct(
        public readonly string $expression,
        public readonly array $values = [],
        private readonly array $criteria = [],
        private readonly ?object $context = null,
    ) {
        if (trim($this->expression) === '') {
            throw new \InvalidArgumentException('DqlExpression expression must not be empty.');
        }

        foreach (array_keys($this->values) as $name) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(sprintf('Invalid DqlExpression variable name "%s".', $name));
            }
            if ($name === 'entity' || $name === 'this') {
                throw new \InvalidArgumentException(sprintf('Variable name "%s" is reserved and cannot be supplied in DqlExpression values.', $name));
            }
        }

        foreach (array_keys($this->criteria) as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException('DqlExpression criteria key must be a non-empty string.');
            }
            if ($name === 'entity' || $name === 'this') {
                throw new \InvalidArgumentException(sprintf('Criteria key "%s" is reserved.', $name));
            }
        }
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function withCriteria(array $criteria): self
    {
        if ($criteria === []) {
            return $this;
        }

        foreach (array_keys($criteria) as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException('DqlExpression criteria key must be a non-empty string.');
            }
            if ($name === 'entity' || $name === 'this') {
                throw new \InvalidArgumentException(sprintf('Criteria key "%s" is reserved.', $name));
            }
            if (array_key_exists($name, $this->criteria)) {
                throw new \LogicException(sprintf('DqlExpression criteria key "%s" already exists.', $name));
            }
        }

        // Also reject collision with explicitly bound variables - criteria are server predicates
        // that must not silently shadow a value binding.
        foreach (array_keys($criteria) as $name) {
            if (array_key_exists($name, $this->values)) {
                throw new \LogicException(sprintf('DqlExpression criteria key "%s" collides with an existing variable.', $name));
            }
        }

        return new self(
            $this->expression,
            $this->values,
            array_merge($this->criteria, $criteria),
            $this->context,
        );
    }

    public function withContext(object $context): self
    {
        if ($this->context !== null) {
            if ($this->context === $context) {
                return $this;
            }
            throw new \LogicException('DqlExpression context is already bound to a different object.');
        }

        return new self(
            $this->expression,
            $this->values,
            $this->criteria,
            $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }

    public function context(): ?object
    {
        return $this->context;
    }

    /**
     * Whether `this` is referenced in the expression.
     */
    public function usesThis(): bool
    {
        return str_contains($this->expression, 'this.');
    }
}
