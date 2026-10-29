<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Value;

/** Immutable category view exposed to template item filters. */
final class PrintCategoryContext
{
    public function __construct(
        private readonly string $uuid,
        private readonly string $name,
        private readonly string $slug,
    ) {}

    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
}
