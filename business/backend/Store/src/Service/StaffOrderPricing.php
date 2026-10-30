<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Store\Entity\Product as CoreProduct;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Trade\Exception\SpecificationNotFoundException;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Canonical employee pricing for an existing customer replacement or an anonymous guest order.
 * Callers own the transaction so the reviewed quote can be rechecked before writing.
 */
final class StaffOrderPricing
{
    /** @param iterable<PriceCalculatorInterface> $calculators */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireIterator('trade.price_calculator')] private readonly iterable $calculators,
    ) {}

    /** @return PriceCalculationResult */
    public function calculate(Store $store, mixed $input, ?User $customer = null): PriceCalculationResult
    {
        if (!$store->isActive()) throw new HttpException(409, 'staff_order_store_unavailable');
        if (!is_array($input) || !array_is_list($input) || count($input) < 1 || count($input) > 30) throw new HttpException(422, 'invalid_staff_order_items');
        $specifications = [];
        $products = [];
        foreach ($input as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['specificationUuid', 'quantity'])
                || !is_string($item['specificationUuid'] ?? null) || !UUID::is_valid($item['specificationUuid'])
                || !is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 99
                || isset($specifications[$item['specificationUuid']])) throw new HttpException(422, 'invalid_staff_order_items');
            $specification = $this->em->getRepository(Specification::class)->findOneBy(['uuid' => $item['specificationUuid']]);
            $product = $specification?->getProduct();
            // Core catalog rows remain orderable; the pricing pipeline applies extension availability rules.
            if (!$product instanceof CoreProduct || $product->getStore()?->getUuid() !== $store->getUuid()) throw new HttpException(422, 'staff_order_item_unavailable');
            $specifications[$specification->getUuid()] = $specification;
            $products[$product->getUuid()] = $product;
        }
        ksort($products); ksort($specifications);
        foreach ([...array_values($products), ...array_values($specifications)] as $entity) $this->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
        foreach ($specifications as $specification) {
            if ($specification->getProduct()?->getStore()?->getUuid() !== $store->getUuid() || $specification->getPrice() < 0 || $specification->getPrice() > 3000000000000) throw new HttpException(422, 'staff_order_item_unavailable');
            if (!isset($products[$specification->getProduct()->getUuid()])) throw new HttpException(409, 'staff_order_item_changed');
        }
        $context = new PriceCalculationContext(array_map(static fn(array $item): array => ['specificationId' => $item['specificationUuid'], 'quantity' => $item['quantity']], $input), $store->getCurrency());
        $context->storeCode = $store->getCode();
        $context->user = $customer;
        $calculators = [...$this->calculators];
        usort($calculators, static fn(PriceCalculatorInterface $a, PriceCalculatorInterface $b): int => $a::getPriority() <=> $b::getPriority());
        try {
            foreach ($calculators as $calculator) $calculator->calculate($context);
        } catch (SpecificationNotFoundException) {
            throw new HttpException(422, 'staff_order_item_unavailable');
        }
        if ($context->currency !== $store->getCurrency() || $context->totalAmount < 0 || $context->totalAmount > 9007199254740991 || count($context->items) !== count($input)) throw new HttpException(422, 'invalid_staff_order_quote');
        foreach ($context->items as $index => $item) {
            if (($item['specificationUuid'] ?? null) !== $input[$index]['specificationUuid'] || ($item['quantity'] ?? null) !== $input[$index]['quantity']) throw new HttpException(422, 'invalid_staff_order_quote');
            foreach (['unitPrice', 'price'] as $field) {
                if (!is_int($item[$field] ?? null) || $item[$field] < 0 || $item[$field] > 9007199254740991) throw new HttpException(422, 'invalid_staff_order_quote');
            }
        }
        return PriceCalculationResult::fromContext($context);
    }
}
