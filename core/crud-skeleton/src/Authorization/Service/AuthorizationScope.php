<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Core\Utils\UUID;
use App\Core\View\ApiViewMessages;

final class AuthorizationScope
{
    public const GLOBAL = 'global';
    public const STORE = 'store';

    public function __construct(
        public readonly string $type,
        public readonly ?string $uuid = null,
    ) {
        if (!\in_array($type, [self::GLOBAL, self::STORE], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid scope type "%s".', $type));
        }
        if ($type === self::GLOBAL && $uuid !== null) {
            throw new \InvalidArgumentException('Global scope requires null uuid.');
        }
        if ($type === self::STORE && ($uuid === null || !UUID::is_valid($uuid))) {
            throw new \InvalidArgumentException(ApiViewMessages::STORE_SCOPE_REQUIRES_VALID_UUID);
        }
    }

    public static function global(): self
    {
        return new self(self::GLOBAL, null);
    }

    public static function store(string $uuid): self
    {
        return new self(self::STORE, $uuid);
    }

    public function isGlobal(): bool
    {
        return $this->type === self::GLOBAL;
    }

    public function isStore(): bool
    {
        return $this->type === self::STORE;
    }
}
