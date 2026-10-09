<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

final class PriceCalculationContext
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $inputItems = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    public int $totalAmount = 0;

    public string $currency = 'CNY';

    /**
     * @var array<string, mixed>
     */
    public array $meta = [];

    /** User object for promotion condition evaluation (member level, etc.) */
    public ?object $user = null;

    /** Store identifier for multi-store promotion filtering */
    public ?string $storeCode = null;

    /**
     * @param list<array<string, mixed>> $inputItems
     */
    public function __construct(array $inputItems, string $currency = 'CNY')
    {
        $this->inputItems = $inputItems;
        $this->currency = $currency;
    }
}
