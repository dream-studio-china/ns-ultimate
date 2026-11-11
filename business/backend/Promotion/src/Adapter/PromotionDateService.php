<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Adapter;

use App\Promotion\Entity\Promotion;
use App\Promotion\Service\PromotionService;
use App\Promotion\Service\PromotionServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** Normalize only campaign dates before the upstream mutable-date mapper. */
#[AsDecorator(decorates: PromotionService::class)]
final class PromotionDateService implements PromotionServiceInterface
{
    public function __construct(#[AutowireDecorated] private readonly PromotionServiceInterface $inner) {}

    public function update($object, ?array $data = null, bool $noFlush = false): object|false
    {
        if (!$object instanceof Promotion || $data === null || (!array_key_exists('startTime', $data) && !array_key_exists('endTime', $data))) {
            return $this->inner->update($object, $data, $noFlush);
        }

        // Parse every supplied date before mutating a managed entity, so a bad
        // second field cannot leave the first changed for a subsequent flush.
        foreach (['startTime', 'endTime'] as $field) {
            if (array_key_exists($field, $data)) $data[$field] = $this->date($data[$field], $field);
        }
        if ($object->getId() !== null) $object = $this->inner->get($object->getId()) ?? $object;
        $start = $object->getStartTime();
        $end = $object->getEndTime();
        try {
            // Non-null DateTimeImmutable values already bypass Core's conversion.
            // Explicit null must bypass it too, rather than becoming "now".
            if (array_key_exists('startTime', $data) && $data['startTime'] === null) {
                $object->setStartTime(null);
                unset($data['startTime']);
            }
            if (array_key_exists('endTime', $data) && $data['endTime'] === null) {
                $object->setEndTime(null);
                unset($data['endTime']);
            }
            $result = $this->inner->update($object, $data, $noFlush);
            if ($result === false) $object->setStartTime($start)->setEndTime($end);
            return $result;
        } catch (\Throwable $error) {
            $object->setStartTime($start)->setEndTime($end);
            throw $error;
        }
    }

    private function date(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null) return null;
        $timezone = new \DateTimeZone(date_default_timezone_get());
        if ($value instanceof \DateTimeInterface) return \DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        // Accept calendar dates, Admin picker timestamps and ISO-8601 offsets;
        // never accept relative expressions such as "tomorrow" or empty strings.
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', trim($value))) {
            throw new \InvalidArgumentException($field.' must be a valid date/time string or null.');
        }
        try {
            $date = new \DateTimeImmutable(trim($value), $timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException($field.' must be a valid date/time string or null.');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new \InvalidArgumentException($field.' must be a valid date/time string or null.');
        }
        // Doctrine datetime_immutable has no timezone column. Normalize before
        // persistence so reloading in the application timezone retains the instant.
        return $date->setTimezone($timezone);
    }

    public function getAvailable(PriceCalculationContext $context, ?int $phase = null, array $excludedIds = []): array
    {
        return $this->inner->getAvailable($context, $phase, $excludedIds);
    }

    public function getFirstAvailable(PriceCalculationContext $context, ?int $phase = null, array $excludedIds = []): ?Promotion
    {
        return $this->inner->getFirstAvailable($context, $phase, $excludedIds);
    }

    public function apply(Promotion $promotion, PriceCalculationContext $context): void { $this->inner->apply($promotion, $context); }
    public function get($object, bool $directly = false) { return $this->inner->get($object, $directly); }
    public function list($object = null, $order = null, bool $disableRequest = true) { return $this->inner->list($object, $order, $disableRequest); }
    public function new() { return $this->inner->new(); }
    public function remove($object): bool { return $this->inner->remove($object); }
    public function wrapInTransaction(callable $fn): mixed { return $this->inner->wrapInTransaction($fn); }
}
