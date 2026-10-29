<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;

/** Validates the bounded rule format without scheduling external work. */
final class OrderPrintRuleValidator
{
    public function __construct(private readonly OrderPrintRuleMatcher $matcher) {}

    /** @param mixed $rules */
    public function validate(mixed $rules): void
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 50) {
            throw new \InvalidArgumentException('Print rules must be a list of at most 50 rules.');
        }
        $seen = [];
        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                throw new \InvalidArgumentException(sprintf('Print rule %d must be an object.', $index + 1));
            }
            $id = $rule['id'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,48}$/D', $id) !== 1 || isset($seen[$id])) {
                throw new \InvalidArgumentException(sprintf('Print rule %d must have a unique stable ID.', $index + 1));
            }
            $seen[$id] = true;
            if (!is_string($rule['name'] ?? null) || trim($rule['name']) === '' || strlen($rule['name']) > 120
                || !is_bool($rule['enabled'] ?? null) || !$this->matcher->isValid($rule)) {
                throw new \InvalidArgumentException(sprintf('Print rule %d has invalid events or conditions.', $index + 1));
            }
            if (!in_array($rule['scene'] ?? null, ['order', 'per_item', 'filtered_list', 'full_list', 'settlement'], true)
                || !is_string($rule['deviceUuid'] ?? null) || !UUID::is_valid($rule['deviceUuid'])
                || !is_string($rule['templateUuid'] ?? null) || !UUID::is_valid($rule['templateUuid'])
                || !is_int($rule['copies'] ?? null) || $rule['copies'] < 1 || $rule['copies'] > 100) {
                throw new \InvalidArgumentException(sprintf('Print rule %d has invalid output configuration.', $index + 1));
            }
        }
    }

    /** Validate output references against the owning Store; does not contact providers. */
    public function validateForStore(mixed $rules, Store $store, EntityManagerInterface $em): void
    {
        $this->validate($rules);
        foreach ($rules as $index => $rule) {
            $device = $em->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $rule['deviceUuid']]);
            $template = $em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $rule['templateUuid']]);
            if (!$device instanceof PrinterDevice || $device->getStoreUuid() !== $store->getUuid()
                || !$template instanceof PrinterTemplate || $device->getKind() !== $template->getKind()) {
                throw new \InvalidArgumentException(sprintf('Print rule %d requires a store-owned device and a matching template.', $index + 1));
            }
        }
    }
}
