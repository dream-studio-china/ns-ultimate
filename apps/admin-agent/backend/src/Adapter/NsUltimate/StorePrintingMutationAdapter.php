<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * App-owned automatic order-printing configuration for one exact Store.
 *
 * Only the five fixed printing cases are writable. Every other Store setting,
 * including unknown stored keys, is preserved. This adapter never submits a
 * print job, contacts a provider, or changes Store lifecycle state.
 */
final class StorePrintingMutationAdapter implements MutationAdapter
{
    private const CASES = ['order', 'perItem', 'filteredList', 'fullList', 'settlement'];
    private const CASE_FIELDS = ['enabled', 'deviceUuid', 'templateUuid', 'copies'];
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $authorization) {}

    public function entityKey(): string { return 'StorePrinting'; }

    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Store printing management requires ROLE_ADMIN.');
        if ($action !== 'update') throw new BadRequestHttpException('Only Store printing configuration updates are supported.');
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        $store = $this->find($identifier, false, false);
        try { $this->em->refresh($store); }
        catch (EntityNotFoundException $error) { throw new NotFoundHttpException('Store not found.', $error); }
        catch (ConversionException|\TypeError $error) { throw new BadRequestHttpException('Stored Store settings are malformed. Use the standard editor.', $error); }
        $this->assertActive($store, false);
        $patch = $this->normalize($changes);
        $settings = $this->settingsOf($store, false);
        $beforePrinting = $this->printingOf($settings, false);
        [$merged, $printing] = $this->merge($settings, $patch, false);
        // Prepare does not lock, but it must not build the preview or hash from
        // stale identity-map dependencies.
        $this->refreshDependencies($printing);
        $this->checkChangedCases($printing, $patch, $store, false);
        $this->validateAllEnabled($printing, $store, false);
        $differences = $this->differences($beforePrinting, $printing, $patch, false);
        if ($differences === []) throw new BadRequestHttpException('The proposed Store printing update contains no effective changes.');
        $beforeHash = $this->hash($this->snapshot($store, $settings, $this->dependencyFingerprints($settings, $patch, false)));
        return [
            'identifier' => (string) $store->getId(),
            'title' => $this->title($store),
            'beforeHash' => $beforeHash,
            'changes' => $patch,
            'differences' => $differences,
        ];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        try {
            $store = $this->find($identifier, true, true);
            try { $this->em->refresh($store, LockMode::PESSIMISTIC_WRITE); }
            catch (EntityNotFoundException $error) { throw new ProposalConflictException('Store no longer exists. Prepare a new preview.', $error); }
            $this->assertActive($store, true);
            $patch = $this->normalize($changes);
            $settings = $this->settingsOf($store, true);
            $beforePrinting = $this->printingOf($settings, true);
            [$merged, $printing] = $this->merge($settings, $patch, true);
            // Lock and refresh the dependency graph BEFORE computing the
            // fingerprints that authorize the mutation. The Store row is
            // already locked, so raw settings cannot change underneath us.
            $this->lockDependencies($printing);
            if (!hash_equals($beforeHash, $this->hash($this->snapshot($store, $settings, $this->dependencyFingerprints($settings, $patch, true))))) {
                throw new ProposalConflictException('Store printing settings changed since preview. Prepare a new preview.');
            }
            $this->checkChangedCases($printing, $patch, $store, true);
            $this->validateAllEnabled($printing, $store, true);
            if ($this->differences($beforePrinting, $printing, $patch, true) === []) {
                throw new BadRequestHttpException('The proposed Store printing update contains no effective changes.');
            }
        } catch (BadRequestHttpException|\InvalidArgumentException $error) {
            throw new ProposalConflictException('Store printing proposal is no longer valid. Prepare a new preview.', $error);
        }

        $store->setSettings($merged);
        $this->em->flush();
        $this->em->refresh($store);
        return [
            'identifier' => (string) $store->getId(),
            'verified' => $this->canonicalize($this->settingsOf($store, true)) === $this->canonicalize($merged),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function normalize(array $changes): array
    {
        if ($changes === [] || array_is_list($changes) || array_diff(array_keys($changes), self::CASES) !== []) {
            throw new BadRequestHttpException('Only the five fixed Store printing cases may be changed.');
        }
        $normalized = [];
        foreach (self::CASES as $caseKey) {
            if (!array_key_exists($caseKey, $changes)) continue;
            $case = $changes[$caseKey];
            if (!is_array($case) || $case === [] || array_is_list($case) || array_diff(array_keys($case), self::CASE_FIELDS) !== []) {
                throw new BadRequestHttpException('Each printing case must be a non-empty object with only enabled, deviceUuid, templateUuid, and copies.');
            }
            $canonical = [];
            if (array_key_exists('enabled', $case)) {
                if (!is_bool($case['enabled'])) throw new BadRequestHttpException('Printing case enabled must be a boolean.');
                $canonical['enabled'] = $case['enabled'];
            }
            foreach (['deviceUuid', 'templateUuid'] as $field) {
                if (!array_key_exists($field, $case)) continue;
                $canonical[$field] = $this->proposalUuid($case[$field], $field);
            }
            if (array_key_exists('copies', $case)) {
                if (!is_int($case['copies']) || $case['copies'] < 1 || $case['copies'] > 100) {
                    throw new BadRequestHttpException('Printing case copies must be an integer from 1 through 100.');
                }
                $canonical['copies'] = $case['copies'];
            }
            $ordered = [];
            foreach (self::CASE_FIELDS as $field) {
                if (array_key_exists($field, $canonical)) { $ordered[$field] = $canonical[$field]; }
            }
            $normalized[$caseKey] = $ordered;
        }
        return $normalized;
    }

    private function proposalUuid(mixed $value, string $field): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw new BadRequestHttpException('Printing case '.$field.' must be a canonical UUID or null.');
        }
        return strtolower($value);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function merge(array $settings, array $patch, bool $commit): array
    {
        $printing = $this->printingOf($settings, $commit);
        foreach ($patch as $caseKey => $casePatch) {
            $existing = [];
            if (array_key_exists($caseKey, $printing)) {
                $existing = $this->map($printing[$caseKey], 'printing case '.$caseKey, $commit);
            }
            foreach (self::CASE_FIELDS as $field) {
                if (array_key_exists($field, $casePatch)) { $existing[$field] = $casePatch[$field]; }
            }
            $printing[$caseKey] = $existing;
        }
        $settings['printing'] = $printing;
        return [$settings, $printing];
    }

    /** @return array<string, mixed> */
    private function settingsOf(Store $store, bool $commit): array
    {
        try { $settings = $store->getSettings(); }
        catch (\TypeError|ConversionException $error) {
            throw $this->invalid($commit, 'Stored Store settings are malformed. Use the standard editor.', $error);
        }
        if ($settings === null) return [];
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw $this->invalid($commit, 'Stored Store settings are malformed. Use the standard editor.');
        }
        return $settings;
    }

    /** @return array<string, mixed> */
    private function printingOf(array $settings, bool $commit): array
    {
        if (!array_key_exists('printing', $settings)) return [];
        return $this->map($settings['printing'], 'printing settings', $commit);
    }

    /** @return array<string, mixed> */
    private function map(mixed $value, string $label, bool $commit): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid($commit, 'Malformed stored '.$label.'. Use the standard editor.');
        }
        return $value;
    }

    /** @return array{enabled: bool, deviceUuid: ?string, templateUuid: ?string, copies: int} */
    private function effectiveCase(mixed $storedCase, string $caseKey, bool $commit): array
    {
        if ($storedCase === null) $storedCase = [];
        if (!is_array($storedCase) || ($storedCase !== [] && array_is_list($storedCase))) {
            throw $this->invalid($commit, 'Stored printing case '.$caseKey.' is malformed. Use the standard editor.');
        }
        $enabled = false;
        if (array_key_exists('enabled', $storedCase)) {
            if (!is_bool($storedCase['enabled'])) throw $this->invalid($commit, 'Stored printing case '.$caseKey.' has an invalid enabled value.');
            $enabled = $storedCase['enabled'];
        }
        $refs = [];
        foreach (['deviceUuid', 'templateUuid'] as $field) {
            if (!array_key_exists($field, $storedCase)) { $refs[$field] = null; continue; }
            $value = $storedCase[$field];
            if ($value !== null && (!is_string($value) || preg_match(self::UUID_PATTERN, $value) !== 1)) {
                throw $this->invalid($commit, 'Stored printing case '.$caseKey.' has an invalid '.$field.'.');
            }
            $refs[$field] = $value === null ? null : strtolower($value);
        }
        $copies = 1;
        if (array_key_exists('copies', $storedCase)) {
            if (!is_int($storedCase['copies']) || $storedCase['copies'] < 1 || $storedCase['copies'] > 100) {
                throw $this->invalid($commit, 'Stored printing case '.$caseKey.' has invalid copies.');
            }
            $copies = $storedCase['copies'];
        }
        return ['enabled' => $enabled, 'deviceUuid' => $refs['deviceUuid'], 'templateUuid' => $refs['templateUuid'], 'copies' => $copies];
    }

    /**
     * @param array<string, mixed> $afterPrinting
     * @param array<string, array<string, mixed>> $patch
     */
    private function checkChangedCases(array $afterPrinting, array $patch, Store $store, bool $commit): void
    {
        foreach (array_keys($patch) as $caseKey) {
            // The merged result must be valid. A malformed stored known field may be
            // repaired only when the explicit patch replaces it; the redacted BEFORE
            // value is handled by differences().
            $after = $this->effectiveCase($afterPrinting[$caseKey] ?? null, $caseKey, $commit);
            if ($after['enabled']) { $this->assertEffective($after, $store, $commit); }
            else { $this->assertNewReferences($after, $patch[$caseKey], $store, $commit); }
        }
    }

    /** @param array{enabled: bool, deviceUuid: ?string, templateUuid: ?string, copies: int} $case */
    private function assertEffective(array $case, Store $store, bool $commit): void
    {
        if (!$case['enabled']) return;
        if ($case['deviceUuid'] === null || $case['templateUuid'] === null) {
            throw $this->invalid($commit, 'An enabled printing case requires both a device UUID and a template UUID.');
        }
        $device = $this->device($case['deviceUuid'], $store, $commit);
        $template = $this->template($case['templateUuid'], $commit);
        if ($device->getKind() !== $template->getKind()) {
            throw $this->invalid($commit, 'The printer device and template kinds must match.');
        }
    }

    /**
     * Even a disabled case must not introduce a new invalid non-null reference.
     * Existing invalid references may remain when the case is being disabled.
     *
     * @param array{enabled: bool, deviceUuid: ?string, templateUuid: ?string, copies: int} $case
     * @param array<string, mixed> $patch
     */
    private function assertNewReferences(array $case, array $patch, Store $store, bool $commit): void
    {
        $newDevice = array_key_exists('deviceUuid', $patch) && $patch['deviceUuid'] !== null;
        $newTemplate = array_key_exists('templateUuid', $patch) && $patch['templateUuid'] !== null;
        $device = $newDevice ? $this->device($case['deviceUuid'], $store, $commit) : null;
        $template = $newTemplate ? $this->template($case['templateUuid'], $commit) : null;
        if (($newDevice || $newTemplate) && $case['deviceUuid'] !== null && $case['templateUuid'] !== null) {
            $device ??= $this->findDevice($case['deviceUuid']);
            $template ??= $this->findTemplate($case['templateUuid']);
            if ($device !== null && $template !== null && $device->getKind() !== $template->getKind()) {
                throw $this->invalid($commit, 'The printer device and template kinds must match.');
            }
        }
    }

    private function device(string $uuid, Store $store, bool $commit): PrinterDevice
    {
        $device = $this->findDevice($uuid);
        if (!$device instanceof PrinterDevice) throw $this->invalid($commit, 'Printer device not found.');
        if ($device->getStoreUuid() === null || strcasecmp($device->getStoreUuid(), $store->getUuid()) !== 0) {
            throw $this->invalid($commit, 'The printer device must belong to this Store.');
        }
        try { $accountEnabled = $device->getAccount()->isEnabled(); }
        catch (EntityNotFoundException $error) {
            throw $this->invalid($commit, 'The printer device account no longer exists.', $error);
        }
        if (!$device->isEnabled() || !$accountEnabled) {
            throw $this->invalid($commit, 'The printer device and its account must be enabled.');
        }
        return $device;
    }

    private function template(string $uuid, bool $commit): PrinterTemplate
    {
        $template = $this->findTemplate($uuid);
        if (!$template instanceof PrinterTemplate) throw $this->invalid($commit, 'Printer template not found.');
        if (!$template->isEnabled()) throw $this->invalid($commit, 'The printer template must be enabled.');
        return $template;
    }

    private function findDevice(string $uuid): ?PrinterDevice
    {
        try { $device = $this->em->getRepository(PrinterDevice::class)->findOneBy(['uuid' => strtolower($uuid)]); }
        catch (ConversionException|\TypeError) { return null; }
        return $device instanceof PrinterDevice ? $device : null;
    }

    private function findTemplate(string $uuid): ?PrinterTemplate
    {
        try { $template = $this->em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => strtolower($uuid)]); }
        catch (ConversionException|\TypeError) { return null; }
        return $template instanceof PrinterTemplate ? $template : null;
    }

    /** @param array<string, mixed> $printing */
    private function validateAllEnabled(array $printing, Store $store, bool $commit): void
    {
        foreach (self::CASES as $caseKey) {
            if (!array_key_exists($caseKey, $printing)) continue;
            $stored = $printing[$caseKey];
            if (!is_array($stored) || ($stored !== [] && array_is_list($stored))) {
                throw $this->invalid($commit, 'Stored printing case '.$caseKey.' is malformed. Use the standard editor.');
            }
            $enabled = false;
            if (array_key_exists('enabled', $stored)) {
                if (!is_bool($stored['enabled'])) throw $this->invalid($commit, 'Stored printing case '.$caseKey.' has an invalid enabled value.');
                $enabled = $stored['enabled'];
            }
            if (!$enabled) continue;
            $case = $this->effectiveCase($stored, $caseKey, $commit);
            $this->assertEffective($case, $store, $commit);
        }
    }

    /**
     * @param array<string, mixed> $beforePrinting
     * @param array<string, mixed> $afterPrinting
     * @param array<string, array<string, mixed>> $patch
     * @return list<array{property: string, before: mixed, after: mixed}>
     */
    private function differences(array $beforePrinting, array $afterPrinting, array $patch, bool $commit): array
    {
        $differences = [];
        foreach (self::CASES as $caseKey) {
            if (!array_key_exists($caseKey, $patch)) continue;
            $hasStored = array_key_exists($caseKey, $beforePrinting);
            $beforeDisplay = null;
            $beforeEffective = null;
            if ($hasStored) {
                try {
                    $beforeEffective = $this->effectiveCase($beforePrinting[$caseKey], $caseKey, $commit);
                    $beforeDisplay = $beforeEffective;
                } catch (BadRequestHttpException|ProposalConflictException) {
                    // A malformed stored known value is never echoed back. The explicit
                    // patch may repair it, and the raw settings hash still detects drift.
                    $beforeEffective = null;
                }
            } else {
                $beforeEffective = $this->effectiveCase(null, $caseKey, $commit);
            }
            $after = $this->effectiveCase($afterPrinting[$caseKey] ?? null, $caseKey, $commit);
            if ($beforeEffective !== null && $beforeEffective === $after) continue;
            $differences[] = ['property' => $caseKey, 'before' => $beforeDisplay, 'after' => $after];
        }
        return $differences;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, array<string, mixed>> $patch
     * @return array<string, array<string, mixed>>
     */
    private function dependencyFingerprints(array $settings, array $patch, bool $commit): array
    {
        $references = [];
        foreach ($this->printingOf($settings, $commit) as $case) {
            if (!is_array($case)) continue;
            foreach (['deviceUuid', 'templateUuid'] as $field) {
                if (is_string($case[$field] ?? null)) $references[$field.':'.strtolower($case[$field])] = [$field, strtolower($case[$field])];
            }
        }
        foreach ($patch as $case) {
            foreach (['deviceUuid', 'templateUuid'] as $field) {
                if (!array_key_exists($field, $case) || $case[$field] === null) continue;
                $references[$field.':'.$case[$field]] = [$field, $case[$field]];
            }
        }
        ksort($references, SORT_STRING);
        $fingerprints = [];
        foreach ($references as $key => [$field, $uuid]) {
            $fingerprints[$key] = $field === 'deviceUuid' ? $this->deviceFingerprint($uuid) : $this->templateFingerprint($uuid);
        }
        return $fingerprints;
    }

    /** @return array<string, mixed> */
    private function deviceFingerprint(string $uuid): array
    {
        $device = $this->findDevice($uuid);
        if (!$device instanceof PrinterDevice) return ['class' => 'PrinterDevice', 'uuid' => $uuid, 'exists' => false];
        try { $account = $device->getAccount(); $accountUuid = $account->getUuid(); $accountEnabled = $account->isEnabled(); }
        catch (EntityNotFoundException) { return ['class' => 'PrinterDevice', 'uuid' => $uuid, 'exists' => false]; }
        return ['class' => 'PrinterDevice', 'uuid' => $uuid, 'exists' => true, 'storeUuid' => $device->getStoreUuid(),
            'kind' => $device->getKind(), 'enabled' => $device->isEnabled(),
            'accountUuid' => $accountUuid, 'accountEnabled' => $accountEnabled];
    }

    /** @return array<string, mixed> */
    private function templateFingerprint(string $uuid): array
    {
        $template = $this->findTemplate($uuid);
        if (!$template instanceof PrinterTemplate) return ['class' => 'PrinterTemplate', 'uuid' => $uuid, 'exists' => false];
        return ['class' => 'PrinterTemplate', 'uuid' => $uuid, 'exists' => true, 'kind' => $template->getKind(), 'enabled' => $template->isEnabled()];
    }

    /**
     * Canonical dependency lock order, identical for every operation:
     * Store row (already locked) -> PrinterDevice rows by ID -> actual current
     * PrinterAccount rows by ID -> PrinterTemplate rows by ID.
     *
     * Accounts are collected only AFTER devices have been locked and refreshed,
     * so a raw-SQL account reassignment cannot leave an old account in the lock
     * set. Device row locks keep the refreshed account association stable while
     * the new account rows are locked and refreshed.
     *
     * @param array<string, mixed> $printing
     */
    private function lockDependencies(array $printing): void
    {
        $devices = $this->sortEntities($this->collectDevices($printing));
        $this->lockAndRefreshEntities($devices);
        $accounts = $this->sortEntities($this->collectAccounts($devices, true));
        $this->lockAndRefreshEntities($accounts);
        $templates = $this->sortEntities($this->collectTemplates($printing));
        $this->lockAndRefreshEntities($templates);
    }

    /**
     * Prepare-side fresh read of the same dependency graph, without row locks.
     *
     * @param array<string, mixed> $printing
     */
    private function refreshDependencies(array $printing): void
    {
        $devices = $this->sortEntities($this->collectDevices($printing));
        $this->refreshEntities($devices, false);
        $accounts = $this->sortEntities($this->collectAccounts($devices, false));
        $this->refreshEntities($accounts, false);
        $templates = $this->sortEntities($this->collectTemplates($printing));
        $this->refreshEntities($templates, false);
    }

    /** @param array<string, mixed> $printing @return list<PrinterDevice> */
    private function collectDevices(array $printing): array
    {
        $devices = [];
        foreach ($printing as $case) {
            if (!is_array($case) || !is_string($case['deviceUuid'] ?? null)) continue;
            $device = $this->findDevice($case['deviceUuid']);
            if ($device instanceof PrinterDevice) $devices[spl_object_id($device)] = $device;
        }
        return array_values($devices);
    }

    /** @param list<PrinterDevice> $devices @return list<\NsUltimate\Business\Printer\Entity\PrinterAccount> */
    private function collectAccounts(array $devices, bool $commit): array
    {
        $accounts = [];
        foreach ($devices as $device) {
            try { $account = $device->getAccount(); }
            catch (EntityNotFoundException $error) {
                throw $this->invalid($commit, 'A referenced printer account changed. Prepare a new preview.', $error);
            }
            $accounts[spl_object_id($account)] = $account;
        }
        return array_values($accounts);
    }

    /** @param array<string, mixed> $printing @return list<PrinterTemplate> */
    private function collectTemplates(array $printing): array
    {
        $templates = [];
        foreach ($printing as $case) {
            if (!is_array($case) || !is_string($case['templateUuid'] ?? null)) continue;
            $template = $this->findTemplate($case['templateUuid']);
            if ($template instanceof PrinterTemplate) $templates[spl_object_id($template)] = $template;
        }
        return array_values($templates);
    }

    /**
     * @param array<int, object> $entities
     * @return list<object>
     */
    private function sortEntities(array $entities): array
    {
        $entities = array_values($entities);
        usort($entities, static fn(object $left, object $right): int => [get_class($left), (int) $left->getId()] <=> [get_class($right), (int) $right->getId()]);
        return $entities;
    }

    /** @param list<object> $entities */
    private function lockAndRefreshEntities(array $entities): void
    {
        foreach ($entities as $entity) {
            try {
                $this->em->lock($entity, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
            } catch (EntityNotFoundException $error) {
                throw new ProposalConflictException('A referenced printer record changed. Prepare a new preview.', $error);
            }
        }
    }

    /** @param list<object> $entities */
    private function refreshEntities(array $entities, bool $commit): void
    {
        foreach ($entities as $entity) {
            try { $this->em->refresh($entity); }
            catch (EntityNotFoundException $error) {
                throw $this->invalid($commit, 'A referenced printer record changed. Prepare a new preview.', $error);
            }
        }
    }

    private function assertActive(Store $store, bool $commit): void
    {
        if ($store->isActive()) return;
        if ($commit) throw new ProposalConflictException('Store is no longer active. Prepare a new preview.');
        throw new BadRequestHttpException('Store must be active to change printing settings.');
    }

    /** @return array<string, mixed> */
    private function snapshot(Store $store, array $settings, array $dependencies): array
    {
        return [
            'store' => ['id' => $store->getId(), 'uuid' => $store->getUuid(), 'code' => $store->getCode(), 'status' => $store->getStatus(), 'settings' => $settings],
            'dependencies' => $dependencies,
        ];
    }

    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR));
    }

    private function title(Store $store): string
    {
        return sprintf(
            '[StorePrinting; Store %s (%s)] Changes FUTURE automatic order-printing settings for orders processed after saving; it does not print anything now or create a print job.',
            $store->getUuid(),
            $store->getName(),
        );
    }

    private function invalid(bool $commit, string $message, ?\Throwable $previous = null): \Throwable
    {
        return $commit ? new ProposalConflictException($message, $previous) : new BadRequestHttpException($message, $previous);
    }

    private function find(?string $identifier, bool $lock, bool $commit): Store
    {
        if ($identifier === null || preg_match('/^(?:[1-9][0-9]{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $identifier) !== 1) {
            throw new BadRequestHttpException('An exact Store ID or UUID is required.');
        }
        try {
            $store = ctype_digit($identifier)
                ? $this->em->find(Store::class, (int) $identifier, $lock ? LockMode::PESSIMISTIC_WRITE : null)
                : $this->em->getRepository(Store::class)->findOneBy(['uuid' => strtolower($identifier)]);
        } catch (ConversionException|\TypeError $error) {
            throw $this->invalid($commit, 'Stored Store settings are malformed. Use the standard editor.', $error);
        }
        if (!$store instanceof Store) {
            if ($commit) throw new ProposalConflictException('Store no longer exists. Prepare a new preview.');
            throw new NotFoundHttpException('Store not found.');
        }
        return $store;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
