<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Value;

/** Immutable item view exposed to template item filters. */
final class PrintItemContext
{
    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $productSettings
     */
    public function __construct(
        private readonly array $item,
        private readonly array $productSettings,
        private readonly ?PrintProductContext $product,
    ) {}

    public function getName(): string { return (string) ($this->item['name'] ?? ''); }
    public function getSpecification(): string { return (string) ($this->item['specification'] ?? ''); }
    public function getQuantity(): int { return (int) ($this->item['quantity'] ?? 0); }
    public function getUnitPrice(): int { return (int) ($this->item['unitPrice'] ?? 0); }
    public function getAmount(): int { return (int) ($this->item['amount'] ?? 0); }
    public function getProductUuid(): ?string { return is_string($this->item['productUuid'] ?? null) ? $this->item['productUuid'] : null; }
    /** @return array<string, mixed> */
    public function getProductSettings(): array { return $this->productSettings; }
    public function getProduct(): ?PrintProductContext { return $this->product; }
}
