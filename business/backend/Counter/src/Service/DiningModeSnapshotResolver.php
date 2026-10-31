<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use NsUltimate\Business\Counter\Entity\CounterSession;

/** Resolves a mode from trusted store settings and freezes it for the active table session. */
final class DiningModeSnapshotResolver
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>|null */
    public function forOrder(Store $store, ?CounterSession $session, array $data): ?array
    {
        return $this->resolve($store, $session, $data['diningModeUuid'] ?? null);
    }

    /** @return array<string, mixed>|null */
    public function resolve(?Store $store, ?CounterSession $session, mixed $requested): ?array
    {
        if ($requested !== null && (!is_string($requested) || !UUID::is_valid($requested))) {
            throw new \InvalidArgumentException('invalid_dining_mode');
        }

        if ($session?->getDiningModeUuid() !== null) {
            $snapshot = $session->getDiningModeSnapshot();
            if (!is_array($snapshot) || ($snapshot['uuid'] ?? null) !== $session->getDiningModeUuid()) {
                throw new \DomainException('invalid_counter_dining_mode');
            }
            if ($requested !== null && !hash_equals($session->getDiningModeUuid(), $requested)) {
                throw new \DomainException('counter_dining_mode_locked');
            }
            return $snapshot;
        }

        if ($requested === null) return null;
        if (!$store instanceof Store) throw new \DomainException('dining_mode_not_configured');

        $settings = $store->getSettings() ?? [];
        $modes = $settings['diningModes']['modes'] ?? null;
        if (!is_array($modes)) throw new \DomainException('dining_mode_not_configured');

        foreach ($modes as $mode) {
            if (!is_array($mode) || ($mode['uuid'] ?? null) !== $requested) continue;
            if (($mode['enabled'] ?? true) !== true) throw new \DomainException('dining_mode_disabled');

            $snapshot = [];
            foreach (['uuid', 'name', 'enabled', 'priority', 'switchButtonLabel', 'availability', 'catalog', 'rules', 'checkoutRules'] as $key) {
                if (array_key_exists($key, $mode)) $snapshot[$key] = $mode[$key];
            }
            if (!is_string($snapshot['name'] ?? null) || !is_array($snapshot['availability'] ?? null) || !is_array($snapshot['catalog'] ?? null)
                || !is_array($snapshot['rules'] ?? null) || !is_array($snapshot['checkoutRules'] ?? [])) {
                throw new \DomainException('invalid_dining_mode');
            }
            if (count($snapshot['rules']) > 50 || count($snapshot['checkoutRules'] ?? []) > 20) {
                throw new \DomainException('invalid_dining_mode');
            }
            $snapshot['checkoutRules'] ??= [];
            if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 65535) {
                throw new \DomainException('dining_mode_snapshot_too_large');
            }
            return $snapshot;
        }

        throw new \DomainException('dining_mode_not_configured');
    }

    /** Binds the selected mode only when the order starts a new table session. */
    public function bind(?Store $store, CounterSession $session, mixed $requested): ?array
    {
        $snapshot = $this->resolve($store, $session, $requested);
        if (is_array($snapshot)) $session->bindDiningMode($snapshot['uuid'], $snapshot);
        return $snapshot;
    }
}
