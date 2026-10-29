<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Value;

/** Immutable product view exposed to template item filters. */
final class PrintProductContext
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly ?string $uuid,
        private readonly string $name,
        private readonly array $settings,
        private readonly ?PrintCategoryContext $category,
    ) {}

    public function getUuid(): ?string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    /** @return array<string, mixed> */
    public function getSettings(): array { return $this->settings; }
    public function getCategory(): ?PrintCategoryContext { return $this->category; }
}
