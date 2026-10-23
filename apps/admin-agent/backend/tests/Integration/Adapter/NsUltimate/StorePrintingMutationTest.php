<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\StorePrintingMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

require_once dirname(__DIR__, 2).'/Support/AdminConfigurationTestManifest.php';

final class StorePrintingMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const URL = '/api/v1/manage/agent/operations';
    private const CASES = ['order', 'perItem', 'filteredList', 'fullList', 'settlement'];
    private const CASE_FIELDS = ['enabled', 'deviceUuid', 'templateUuid', 'copies'];
    private ?string $testManifest = null;

    protected function setUp(): void {
        $this->testManifest = \NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::install();
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void {
        if ($this->testManifest !== null) {
            \NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::restore($this->testManifest);
            $this->testManifest = null;
        }
    }
    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testPartialPatchPreservesUnrelatedSettingsAndUnknownStoredKeys(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template, , $other] = $this->fixture($em, [
            'paymentRate' => 0.006,
            'order' => ['requireAcceptance' => true, 'requireInventory' => false],
            'fulfillment' => ['requireVerification' => true],
            'printing' => [
                'order' => ['enabled' => false, 'copies' => 2, 'legacyNote' => 'keep'],
                'perItem' => ['enabled' => false, 'copies' => 1],
                'unknownCase' => ['note' => 'preserve'],
            ],
            'customRoot' => ['keep' => ['nested' => true]],
        ], 'partial');

        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'enabled' => true],
            'perItem' => ['copies' => 3],
        ]]);

        self::assertSame((string) $store->getId(), $draft['identifier']);
        self::assertSame($this->title($store), $draft['title']);
        self::assertStringContainsString('FUTURE', $draft['title']);
        self::assertStringContainsString('does not print anything now', $draft['title']);
        self::assertSame(['order', 'perItem'], array_column($draft['differences'], 'property'));
        self::assertSame(['enabled' => false, 'deviceUuid' => null, 'templateUuid' => null, 'copies' => 2], $draft['differences'][0]['before']);
        self::assertSame(['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 2], $draft['differences'][0]['after']);
        self::assertSame(['enabled', 'deviceUuid', 'templateUuid', 'copies'], array_keys($draft['differences'][0]['after']));
        self::assertSame(['enabled' => false, 'deviceUuid' => null, 'templateUuid' => null, 'copies' => 1], $draft['differences'][1]['before']);
        self::assertSame(['enabled' => false, 'deviceUuid' => null, 'templateUuid' => null, 'copies' => 3], $draft['differences'][1]['after']);

        $encoded = json_encode($draft, JSON_THROW_ON_ERROR);
        foreach (['legacyNote', 'paymentRate', 'requireAcceptance', 'customRoot', 'unknownCase', (string) $other->getCode()] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $encoded);
        }

        $receipt = $service->commit('actor', $draft['operationId']);
        self::assertTrue($receipt['verified']);
        $em->clear();
        $updated = $em->find(Store::class, $store->getId());
        $settings = $updated->getSettings();
        self::assertSame(0.006, $settings['paymentRate']);
        self::assertSame(['requireAcceptance' => true, 'requireInventory' => false], $settings['order']);
        self::assertSame(['requireVerification' => true], $settings['fulfillment']);
        self::assertSame(['keep' => ['nested' => true]], $settings['customRoot']);
        self::assertSame('preserve', $settings['printing']['unknownCase']['note']);
        self::assertSame('keep', $settings['printing']['order']['legacyNote']);
        self::assertSame(2, $settings['printing']['order']['copies']);
        self::assertTrue($settings['printing']['order']['enabled']);
        self::assertSame($device->getUuid(), $settings['printing']['order']['deviceUuid']);
        self::assertSame($template->getUuid(), $settings['printing']['order']['templateUuid']);
        self::assertSame(3, $settings['printing']['perItem']['copies']);
        self::assertFalse($settings['printing']['perItem']['enabled']);
        $otherAfter = $em->find(Store::class, $other->getId());
        self::assertSame(['paymentRate' => 0.25, 'printing' => ['order' => ['enabled' => false, 'copies' => 7]]], $otherAfter->getSettings());
        self::assertSame(0, $em->getRepository(PrinterJob::class)->count([]));
        self::assertSame(0, $em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    public function testExactSchemaUuidBoundsAndReferenceRules(): void
    {
        [$service, $em] = $this->operationService();
        [$store] = $this->fixture($em, null, 'validation');
        $otherStore = new Store('validation-other', 'Validation Other');
        $account = new PrinterAccount('Validation account', 'validation-developer', 'test-cipher');
        $device = new PrinterDevice($account, 'Validation device', 'validation-serial', PrinterDevice::KIND_RECEIPT, $store->getUuid());
        $labelDevice = new PrinterDevice($account, 'Label device', 'validation-label', PrinterDevice::KIND_LABEL, $store->getUuid(), widthMm: 50, heightMm: 40);
        $disabledDevice = (new PrinterDevice($account, 'Disabled device', 'validation-disabled', PrinterDevice::KIND_RECEIPT, $store->getUuid()))->setEnabled(false);
        $noStoreDevice = new PrinterDevice($account, 'No store device', 'validation-no-store', PrinterDevice::KIND_RECEIPT);
        $otherAccount = new PrinterAccount('Other account', 'other-developer', 'test-cipher');
        $crossStoreDevice = new PrinterDevice($otherAccount, 'Cross store device', 'validation-cross', PrinterDevice::KIND_RECEIPT, $otherStore->getUuid());
        $disabledAccount = (new PrinterAccount('Disabled account', 'disabled-developer', 'test-cipher'))->setEnabled(false);
        $accountDisabledDevice = new PrinterDevice($disabledAccount, 'Account disabled device', 'validation-account-disabled', PrinterDevice::KIND_RECEIPT, $store->getUuid());
        $template = new PrinterTemplate('Validation receipt', PrinterDevice::KIND_RECEIPT);
        $labelTemplate = new PrinterTemplate('Validation label', PrinterDevice::KIND_LABEL, ['kind' => PrinterDevice::KIND_LABEL, 'nodes' => []]);
        $disabledTemplate = (new PrinterTemplate('Disabled template', PrinterDevice::KIND_RECEIPT))->setEnabled(false);
        $missingUuid = '00000000-0000-4000-8000-000000000999';
        $em->persist($otherStore);
        $em->persist($account);
        $em->persist($device);
        $em->persist($labelDevice);
        $em->persist($disabledDevice);
        $em->persist($noStoreDevice);
        $em->persist($otherAccount);
        $em->persist($crossStoreDevice);
        $em->persist($disabledAccount);
        $em->persist($accountDisabledDevice);
        $em->persist($template);
        $em->persist($labelTemplate);
        $em->persist($disabledTemplate);
        $em->flush();

        $valid = ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()];
        $invalid = [
            [],
            ['printing' => ['order' => ['copies' => 2]]],
            ['order' => null],
            ['order' => []],
            ['order' => ['unknown' => true]],
            ['order' => ['enabled' => 1]],
            ['order' => ['enabled' => 'true']],
            ['order' => ['copies' => 0]],
            ['order' => ['copies' => 101]],
            ['order' => ['copies' => '2']],
            ['order' => ['copies' => 1.5]],
            ['order' => ['deviceUuid' => '']],
            ['order' => ['deviceUuid' => 'not-a-uuid']],
            ['order' => ['templateUuid' => 123]],
            ['order' => ['enabled' => true, 'deviceUuid' => $missingUuid, 'templateUuid' => $template->getUuid()]],
            ['order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $missingUuid]],
            ['order' => ['enabled' => true, 'deviceUuid' => $disabledDevice->getUuid(), 'templateUuid' => $template->getUuid()]],
            ['order' => ['enabled' => true, 'deviceUuid' => $accountDisabledDevice->getUuid(), 'templateUuid' => $template->getUuid()]],
            ['order' => ['enabled' => true, 'deviceUuid' => $crossStoreDevice->getUuid(), 'templateUuid' => $template->getUuid()]],
            ['order' => ['enabled' => true, 'deviceUuid' => $noStoreDevice->getUuid(), 'templateUuid' => $template->getUuid()]],
            ['order' => ['enabled' => true, 'deviceUuid' => $labelDevice->getUuid(), 'templateUuid' => $template->getUuid()]],
            ['order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $labelTemplate->getUuid()]],
            ['order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $disabledTemplate->getUuid()]],
            ['order' => ['enabled' => false, 'deviceUuid' => $crossStoreDevice->getUuid()]],
            ['order' => ['enabled' => false, 'deviceUuid' => $labelDevice->getUuid(), 'templateUuid' => $template->getUuid()]],
        ];
        foreach ($invalid as $changes) {
            try {
                $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => $changes]);
                self::fail('Invalid Store printing proposal was accepted: '.json_encode($changes, JSON_THROW_ON_ERROR));
            } catch (BadRequestHttpException) {
                self::assertNull($em->find(Store::class, $store->getId())->getSettings());
            }
        }

        foreach ([(string) $store->getId(), $store->getUuid()] as $identifier) {
            $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => $identifier, 'changes' => ['order' => $valid + ['copies' => 1]]]);
            self::assertSame('order', $draft['differences'][0]['property']);
            self::assertSame($device->getUuid(), $draft['differences'][0]['after']['deviceUuid']);
        }
        foreach ([['action' => 'create', 'identifier' => null, 'changes' => ['order' => ['copies' => 2]]], ['action' => 'delete', 'identifier' => (string) $store->getId(), 'changes' => []]] as $proposal) {
            try { $service->prepare('actor', ['entity' => 'StorePrinting', ...$proposal]); self::fail('Non-update Store printing action was accepted.'); }
            catch (BadRequestHttpException) {}
        }
    }

    public function testEnableOnlyPatchUsesValidStoredReferencesAndCopies(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'enable-only');
        $store->setSettings(['printing' => ['order' => [
            'enabled' => false, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 2,
        ]]]);
        $em->flush();
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => ['order' => ['enabled' => true]]]);
        self::assertSame(['enabled' => false, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 2], $draft['differences'][0]['before']);
        self::assertSame(['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 2], $draft['differences'][0]['after']);
        self::assertSame(['order' => ['enabled' => true]], $em->find(AgentOperation::class, $draft['operationId'])->getChanges());
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);
        $em->clear();
        self::assertTrue($em->find(Store::class, $store->getId())->getSettings()['printing']['order']['enabled']);
    }

    public function testStaleDisabledOrForeignReferencesCanBeDisabledWithoutExplicitRefChanges(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template, , $otherStore] = $this->fixture($em, null, 'stale-typed');
        $disabledDevice = (new PrinterDevice($device->getAccount(), 'Disabled stale', 'stale-disabled-serial', PrinterDevice::KIND_RECEIPT, $store->getUuid()))->setEnabled(false);
        $disabledTemplate = (new PrinterTemplate('Disabled stale template', PrinterDevice::KIND_RECEIPT))->setEnabled(false);
        $otherAccount = new PrinterAccount('Other stale account', 'stale-developer', 'test-cipher');
        $foreignDevice = new PrinterDevice($otherAccount, 'Foreign stale', 'stale-foreign-serial', PrinterDevice::KIND_RECEIPT, $otherStore->getUuid());
        $em->persist($disabledDevice);
        $em->persist($disabledTemplate);
        $em->persist($otherAccount);
        $em->persist($foreignDevice);
        $em->flush();
        $store->setSettings(['printing' => [
            'order' => ['enabled' => true, 'deviceUuid' => $disabledDevice->getUuid(), 'templateUuid' => $disabledTemplate->getUuid(), 'copies' => 2],
            'perItem' => ['enabled' => true, 'deviceUuid' => $foreignDevice->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 3],
        ]]);
        $em->flush();
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => false],
            'perItem' => ['enabled' => false],
        ]]);
        self::assertSame(['order', 'perItem'], array_column($draft['differences'], 'property'));
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);
        $em->clear();
        $settings = $em->find(Store::class, $store->getId())->getSettings();
        self::assertFalse($settings['printing']['order']['enabled']);
        self::assertFalse($settings['printing']['perItem']['enabled']);
        self::assertSame($disabledDevice->getUuid(), $settings['printing']['order']['deviceUuid']);
        self::assertSame($disabledTemplate->getUuid(), $settings['printing']['order']['templateUuid']);
        self::assertSame($foreignDevice->getUuid(), $settings['printing']['perItem']['deviceUuid']);
    }

    public function testStaleCasesCanBeDisabledAndReferencesClearedOnlyWhenDisabled(): void
    {
        [$service, $em] = $this->operationService();
        $missingDevice = '00000000-0000-4000-8000-000000000111';
        $missingTemplate = '00000000-0000-4000-8000-000000000222';
        [$store, $device, $template] = $this->fixture($em, null, 'stale');
        $store->setSettings([
            'printing' => [
                'order' => ['enabled' => true, 'deviceUuid' => $missingDevice, 'templateUuid' => $missingTemplate, 'copies' => 4],
                'perItem' => ['enabled' => false, 'deviceUuid' => $missingDevice, 'templateUuid' => $missingTemplate, 'copies' => 2],
                'fullList' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 1],
            ],
        ]);
        $em->flush();

        $disable = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => ['order' => ['enabled' => false]]]);
        self::assertSame(['enabled' => true, 'deviceUuid' => $missingDevice, 'templateUuid' => $missingTemplate, 'copies' => 4], $disable['differences'][0]['before']);
        self::assertSame(['enabled' => false, 'deviceUuid' => $missingDevice, 'templateUuid' => $missingTemplate, 'copies' => 4], $disable['differences'][0]['after']);
        self::assertTrue($service->commit('actor', $disable['operationId'])['verified']);

        $clear = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => ['perItem' => ['enabled' => false, 'deviceUuid' => null, 'templateUuid' => null]]]);
        self::assertTrue($service->commit('actor', $clear['operationId'])['verified']);
        $em->clear();
        $settings = $em->find(Store::class, $store->getId())->getSettings();
        self::assertFalse($settings['printing']['order']['enabled']);
        self::assertSame($missingDevice, $settings['printing']['order']['deviceUuid']);
        self::assertSame(4, $settings['printing']['order']['copies']);
        self::assertFalse($settings['printing']['perItem']['enabled']);
        self::assertNull($settings['printing']['perItem']['deviceUuid']);
        self::assertNull($settings['printing']['perItem']['templateUuid']);

        $store = $em->find(Store::class, $store->getId());
        $store->setSettings(['printing' => ['fullList' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 1]]]);
        $em->flush();
        foreach ([
            ['fullList' => ['deviceUuid' => null]],
            ['fullList' => ['deviceUuid' => null, 'templateUuid' => null]],
            ['fullList' => ['enabled' => true, 'deviceUuid' => null, 'templateUuid' => $template->getUuid()]],
            ['fullList' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => null]],
        ] as $changes) {
            try {
                $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => $changes]);
                self::fail('Clearing a reference while the effective case is enabled was accepted.');
            } catch (BadRequestHttpException) {}
        }
        $disableAndClear = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => ['fullList' => ['enabled' => false, 'deviceUuid' => null, 'templateUuid' => null]]]);
        self::assertTrue($service->commit('actor', $disableAndClear['operationId'])['verified']);
    }

    public function testUntouchedInvalidEnabledCaseIsRejectedAtPrepare(): void
    {
        [$service, $em] = $this->operationService();
        $missingDevice = '00000000-0000-4000-8000-000000000333';
        $missingTemplate = '00000000-0000-4000-8000-000000000444';
        [$store] = $this->fixture($em, [
            'printing' => [
                'perItem' => ['enabled' => true, 'deviceUuid' => $missingDevice, 'templateUuid' => $missingTemplate, 'copies' => 2],
            ],
        ], 'full-validation');
        try {
            $service->prepare('full-validation-actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => ['order' => ['copies' => 2]]]);
            self::fail('A merged configuration with an untouched invalid enabled case was prepared.');
        } catch (BadRequestHttpException) {
            $settings = json_decode((string) $em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]), true);
            self::assertTrue($settings['printing']['perItem']['enabled']);
            self::assertArrayNotHasKey('order', $settings['printing']);
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM admin_agent_operation WHERE actor = ?', ['full-validation-actor']));
        }
    }

    public function testEffectiveNoOpsAreRejected(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'noop');
        $store->setSettings(['printing' => [
            'order' => ['enabled' => false, 'copies' => 2],
            'perItem' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 1],
        ]]);
        $em->flush();
        foreach ([
            [],
            ['order' => ['enabled' => false]],
            ['order' => ['copies' => 2]],
            ['order' => ['deviceUuid' => null]],
            ['order' => ['enabled' => false, 'copies' => 2, 'deviceUuid' => null, 'templateUuid' => null]],
            ['settlement' => ['copies' => 1]],
            ['perItem' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()]],
        ] as $changes) {
            try {
                $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => $changes]);
                self::fail('Effective no-op was accepted: '.json_encode($changes, JSON_THROW_ON_ERROR));
            } catch (BadRequestHttpException) {}
        }
        self::assertSame(2, $em->find(Store::class, $store->getId())->getSettings()['printing']['order']['copies']);
    }

    public function testMalformedStoredSettingsFailClosedWithoutTypeErrors(): void
    {
        [$service, $em] = $this->operationService();
        [$store] = $this->fixture($em, null, 'malformed');
        $malformed = [
            '"invalid"',
            '[1,2]',
            '{"printing":"invalid"}',
            '{"printing":{"order":"invalid"}}',
            '{"printing":{"order":{"enabled":1}}}',
            '{"printing":{"order":{"deviceUuid":"not-a-uuid"}}}',
            '{"printing":null}',
            '{"printing":{"order":null}}',
            '{',
        ];
        foreach ($malformed as $json) {
            $em->getConnection()->executeStatement('UPDATE store SET settings = ? WHERE id = ?', [$json, $store->getId()]);
            $em->clear();
            try {
                $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => ['order' => ['copies' => 2]]]);
                self::fail('Malformed stored settings were accepted: '.$json);
            } catch (BadRequestHttpException) {
                self::assertTrue(true);
            }
        }

        $em->getConnection()->executeStatement('UPDATE store SET settings = ? WHERE id = ?', ['{"printing":{"order":{"enabled":false,"copies":2}}}', $store->getId()]);
        $em->clear();
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => ['order' => ['copies' => 3]]]);
        $em->getConnection()->executeStatement('UPDATE store SET settings = ? WHERE id = ?', ['{"printing":"invalid"}', $store->getId()]);
        $em->clear();
        try {
            $service->commit('actor', $draft['operationId']);
            self::fail('A commit with malformed stored settings was accepted.');
        } catch (ProposalConflictException) {
            self::assertSame('{"printing":"invalid"}', $em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
        }
    }

    public function testMalformedKnownFieldsCanBeRepairedWithRedactedBeforeAndPreservedUnknownKeys(): void
    {
        [$service, $em] = $this->operationService();
        [$store] = $this->fixture($em, null, 'repair');
        $em->getConnection()->executeStatement('UPDATE store SET settings = ? WHERE id = ?', [
            '{"paymentRate":0.006,"printing":{"order":{"enabled":1,"copies":"2","deviceUuid":"not-a-uuid","templateUuid":null,"legacy":"keep"}}}',
            $store->getId(),
        ]);
        $em->clear();
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => false, 'copies' => 1, 'deviceUuid' => null, 'templateUuid' => null],
        ]]);
        self::assertNull($draft['differences'][0]['before']);
        self::assertSame(['enabled' => false, 'deviceUuid' => null, 'templateUuid' => null, 'copies' => 1], $draft['differences'][0]['after']);
        self::assertSame(['enabled', 'deviceUuid', 'templateUuid', 'copies'], array_keys($draft['differences'][0]['after']));
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);
        $em->clear();
        self::assertEquals(['paymentRate' => 0.006, 'printing' => ['order' => ['enabled' => false, 'copies' => 1, 'deviceUuid' => null, 'templateUuid' => null, 'legacy' => 'keep']]], $em->find(Store::class, $store->getId())->getSettings());
    }

    public function testAllFiveCasesUseFixedOrderAndCanonicalStateCanBeRestored(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, [
            'paymentRate' => 0.006,
            'order' => ['requireAcceptance' => true],
            'custom' => ['first' => 1, 'second' => 2],
        ], 'all-cases');
        $changes = [
            'settlement' => ['copies' => 5, 'templateUuid' => $template->getUuid(), 'enabled' => true, 'deviceUuid' => $device->getUuid()],
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 2],
            'filteredList' => ['templateUuid' => $template->getUuid(), 'copies' => 3, 'deviceUuid' => $device->getUuid(), 'enabled' => true],
            'fullList' => ['enabled' => true, 'copies' => 4, 'templateUuid' => $template->getUuid(), 'deviceUuid' => $device->getUuid()],
            'perItem' => ['deviceUuid' => $device->getUuid(), 'enabled' => true, 'templateUuid' => $template->getUuid(), 'copies' => 1],
        ];
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => $changes]);
        self::assertSame(self::CASES, array_column($draft['differences'], 'property'));
        foreach ($draft['differences'] as $difference) {
            self::assertSame(self::CASE_FIELDS, array_keys($difference['after']));
        }
        $operation = $em->find(AgentOperation::class, $draft['operationId']);
        self::assertSame(self::CASES, array_keys($operation->getChanges()));
        foreach ($operation->getChanges() as $case) self::assertSame(self::CASE_FIELDS, array_keys($case));

        $storedChanges = $em->getConnection()->fetchOne('SELECT changes FROM admin_agent_operation WHERE id = ?', [$draft['operationId']]);
        $storedSettings = $em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]);
        $em->getConnection()->executeStatement('UPDATE admin_agent_operation SET changes = ? WHERE id = ?', [json_encode($this->reverseMaps(json_decode((string) $storedChanges, true)), JSON_THROW_ON_ERROR), $draft['operationId']]);
        $em->getConnection()->executeStatement('UPDATE store SET settings = ? WHERE id = ?', [json_encode($this->reverseMaps(json_decode((string) $storedSettings, true)), JSON_THROW_ON_ERROR), $store->getId()]);
        $em->clear();

        $restored = $service->restore('actor', $draft['operationId']);
        self::assertSame($draft['differences'], $restored['differences']);
        self::assertSame($draft['title'], $restored['title']);
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);

        $em->clear();
        $settings = $em->find(Store::class, $store->getId())->getSettings();
        self::assertSame(0.006, $settings['paymentRate']);
        self::assertEquals(['requireAcceptance' => true], $settings['order']);
        self::assertEquals(['first' => 1, 'second' => 2], $settings['custom']);
        foreach (['order' => 2, 'perItem' => 1, 'filteredList' => 3, 'fullList' => 4, 'settlement' => 5] as $case => $copies) {
            self::assertSame($copies, $settings['printing'][$case]['copies']);
            self::assertTrue($settings['printing'][$case]['enabled']);
            self::assertSame($device->getUuid(), $settings['printing'][$case]['deviceUuid']);
            self::assertSame($template->getUuid(), $settings['printing'][$case]['templateUuid']);
        }
        self::assertSame(0, $em->getRepository(PrinterJob::class)->count([]));
        self::assertSame(0, $em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    public function testExpiredOperationCannotBeCommitted(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'expiry');
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $operation = $em->find(AgentOperation::class, $draft['operationId']);
        (new \ReflectionProperty(AgentOperation::class, 'expiresAt'))->setValue($operation, new \DateTimeImmutable('-1 minute'));
        $em->flush();
        try { $service->commit('actor', $draft['operationId']); self::fail('Expired operation was committed.'); }
        catch (ConflictHttpException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
        }
    }

    public function testRevokedRoleCannotCommit(): void
    {
        $checker = new SwitchableStorePrintingAuthorization();
        [$service, $em] = $this->operationService($checker);
        [$store, $device, $template] = $this->fixture($em, null, 'role');
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $checker->granted = false;
        try { $service->commit('actor', $draft['operationId']); self::fail('Revoked role was allowed to commit.'); }
        catch (AccessDeniedException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
        }
    }

    public function testChangedDeviceReferenceConflictsAtCommit(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'reference');
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $device->setEnabled(false);
        $em->flush();
        try { $service->commit('actor', $draft['operationId']); self::fail('Changed device reference was committed.'); }
        catch (ProposalConflictException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
        }
    }

    public function testUnrelatedStoredSettingsChangeConflictsAtCommit(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'unrelated');
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $em->getConnection()->executeStatement('UPDATE store SET settings = ? WHERE id = ?', ['{"paymentRate":0.9}', $store->getId()]);
        $em->clear();
        try { $service->commit('actor', $draft['operationId']); self::fail('Unrelated stored settings change was not detected.'); }
        catch (ProposalConflictException) {
            self::assertSame('{"paymentRate":0.9}', $em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
        }
    }

    public function testSuspendedStoreConflictsAtCommit(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'suspended');
        $draft = $service->prepare('actor', ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $store->suspend();
        $em->flush();
        try { $service->commit('actor', $draft['operationId']); self::fail('Suspended Store was committed.'); }
        catch (ProposalConflictException) {
            self::assertSame(Store::STATUS_SUSPENDED, $em->getConnection()->fetchOne('SELECT status FROM store WHERE id = ?', [$store->getId()]));
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
        }
    }

    public function testRawSqlDeviceAccountReassignmentConflictsBeforeMutation(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'account-reassign');
        $newAccount = (new PrinterAccount('Reassigned account', 'reassigned-developer', 'test-cipher'))->setEnabled(true);
        $em->persist($newAccount);
        $em->flush();
        $actor = 'account-reassign-actor';
        $draft = $service->prepare($actor, ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        // External writer changes the device's account. The managed device and
        // accounts keep the old values, so a stale identity map must not pass.
        $em->getConnection()->executeStatement('UPDATE printer_device SET account_id = ? WHERE id = ?', [$newAccount->getId(), $device->getId()]);
        try { $service->commit($actor, $draft['operationId']); self::fail('A raw-SQL account reassignment was committed.'); }
        catch (ProposalConflictException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
            self::assertSame($newAccount->getId(), (int) $em->getConnection()->fetchOne('SELECT account_id FROM printer_device WHERE id = ?', [$device->getId()]));
        }
    }

    public function testRawSqlDisabledActualNewAccountConflictsWithoutIdentityMapClear(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'new-account-disabled');
        $newAccount = (new PrinterAccount('New disabled account', 'new-disabled-developer', 'test-cipher'))->setEnabled(true);
        $em->persist($newAccount);
        $em->flush();
        $actor = 'new-account-disabled-actor';
        $draft = $service->prepare($actor, ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $em->getConnection()->executeStatement('UPDATE printer_device SET account_id = ? WHERE id = ?', [$newAccount->getId(), $device->getId()]);
        $em->getConnection()->executeStatement('UPDATE printer_account SET enabled = 0 WHERE id = ?', [$newAccount->getId()]);
        try { $service->commit($actor, $draft['operationId']); self::fail('A disabled actual new account was committed.'); }
        catch (ProposalConflictException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT enabled FROM printer_account WHERE id = ?', [$newAccount->getId()]));
            self::assertSame($newAccount->getId(), (int) $em->getConnection()->fetchOne('SELECT account_id FROM printer_device WHERE id = ?', [$device->getId()]));
        }
    }

    public function testRawSqlAccountRevocationConflictsWithoutIdentityMapClear(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template, $account] = $this->fixture($em, null, 'account-revoked');
        $actor = 'account-revoked-actor';
        $draft = $service->prepare($actor, ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $em->getConnection()->executeStatement('UPDATE printer_account SET enabled = 0 WHERE id = ?', [$account->getId()]);
        try { $service->commit($actor, $draft['operationId']); self::fail('A raw-SQL account revocation was committed.'); }
        catch (ProposalConflictException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT enabled FROM printer_account WHERE id = ?', [$account->getId()]));
        }
    }

    public function testRawSqlTemplateRevocationConflictsWithoutIdentityMapClear(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template] = $this->fixture($em, null, 'template-revoked');
        $actor = 'template-revoked-actor';
        $draft = $service->prepare($actor, ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $em->getConnection()->executeStatement('UPDATE printer_template SET enabled = 0 WHERE id = ?', [$template->getId()]);
        try { $service->commit($actor, $draft['operationId']); self::fail('A raw-SQL template revocation was committed.'); }
        catch (ProposalConflictException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT enabled FROM printer_template WHERE id = ?', [$template->getId()]));
        }
    }

    public function testRawSqlDeviceStoreReassignmentConflictsWithoutIdentityMapClear(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $device, $template, , $otherStore] = $this->fixture($em, null, 'store-owner-reassigned');
        $actor = 'store-owner-reassigned-actor';
        $draft = $service->prepare($actor, ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        $em->getConnection()->executeStatement('UPDATE printer_device SET store_uuid = ? WHERE id = ?', [$otherStore->getUuid(), $device->getId()]);
        try { $service->commit($actor, $draft['operationId']); self::fail('A raw-SQL device Store reassignment was committed.'); }
        catch (ProposalConflictException) {
            self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
            self::assertSame($otherStore->getUuid(), $em->getConnection()->fetchOne('SELECT store_uuid FROM printer_device WHERE id = ?', [$device->getId()]));
        }
    }

    public function testRawSqlDeviceAccountReassignmentReturnsProposalConflictOverHttp(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$store, $device, $template] = $this->fixture($em, null, 'http-account-conflict');
        $newAccount = (new PrinterAccount('HTTP reassigned account', 'http-reassigned-developer', 'test-cipher'))->setEnabled(true);
        $em->persist($newAccount);
        $em->flush();
        $client->jsonRequest('POST', self::URL, ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => (string) $store->getId(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid()],
        ]]);
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $preview = $preview['data'] ?? $preview;
        $em->getConnection()->executeStatement('UPDATE printer_device SET account_id = ? WHERE id = ?', [$newAccount->getId(), $device->getId()]);
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('proposal_conflict', $body['error'] ?? null);
        self::assertNull($em->getConnection()->fetchOne('SELECT settings FROM store WHERE id = ?', [$store->getId()]));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
    }

    public function testHttpPrepareRestoreAndCommitUseRegisteredAdapter(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$store, $device, $template] = $this->fixture($em, null, 'http');
        $client->jsonRequest('POST', self::URL, ['entity' => 'StorePrinting', 'action' => 'update', 'identifier' => $store->getUuid(), 'changes' => [
            'order' => ['enabled' => true, 'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 2],
        ]]);
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $preview = $preview['data'] ?? $preview;
        self::assertSame('StorePrinting', $preview['entity']);
        self::assertSame('update', $preview['action']);
        self::assertSame((string) $store->getId(), $preview['identifier']);
        self::assertSame($this->title($store), $preview['title']);
        self::assertFalse($preview['saved']);
        self::assertSame(1, $preview['affectedRecords']);
        self::assertSame(['order'], array_column($preview['differences'], 'property'));
        self::assertSame(self::CASE_FIELDS, array_keys($preview['differences'][0]['after']));

        $client->request('GET', self::URL.'/'.$preview['operationId'].'/preview');
        self::assertResponseIsSuccessful();
        $restored = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $restored = $restored['data'] ?? $restored;
        self::assertSame($preview['differences'], $restored['differences']);

        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $receipt = $body['data'] ?? $body;
        self::assertTrue($receipt['verified']);
        self::assertSame('succeeded', $receipt['status']);
        $em->clear();
        $settings = $em->find(Store::class, $store->getId())->getSettings();
        self::assertTrue($settings['printing']['order']['enabled']);
        self::assertSame(2, $settings['printing']['order']['copies']);
        self::assertSame(0, $em->getRepository(PrinterJob::class)->count([]));
        self::assertSame(0, $em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    /** @return array{0: AgentOperationService, 1: EntityManagerInterface} */
    private function operationService(?SwitchableStorePrintingAuthorization $checker = null): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $checker ??= new SwitchableStorePrintingAuthorization();
        return [new AgentOperationService($em, new MutationAdapterRegistry([new StorePrintingMutationAdapter($em, $checker)]), \NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::legacyPolicy()), $em];
    }

    /**
     * @param array<string, mixed>|null $settings
     * @return array{0: Store, 1: PrinterDevice, 2: PrinterTemplate, 3: PrinterAccount, 4: Store}
     */
    private function fixture(EntityManagerInterface $em, ?array $settings, string $code): array
    {
        $store = (new Store('printing-'.$code, 'Printing Store '.$code))->setSettings($settings);
        $other = (new Store('printing-'.$code.'-other', 'Other Store '.$code))->setSettings(['paymentRate' => 0.25, 'printing' => ['order' => ['enabled' => false, 'copies' => 7]]]);
        $account = new PrinterAccount('Printer account '.$code, 'developer-'.$code, 'test-cipher');
        $device = new PrinterDevice($account, 'Receipt device '.$code, 'serial-'.$code, PrinterDevice::KIND_RECEIPT, $store->getUuid());
        $template = new PrinterTemplate('Receipt template '.$code, PrinterDevice::KIND_RECEIPT);
        $em->persist($store);
        $em->persist($other);
        $em->persist($account);
        $em->persist($device);
        $em->persist($template);
        $em->flush();
        return [$store, $device, $template, $account, $other];
    }

    private function title(Store $store): string
    {
        return sprintf(
            '[StorePrinting; Store %s (%s)] Changes FUTURE automatic order-printing settings for orders processed after saving; it does not print anything now or create a print job.',
            $store->getUuid(),
            $store->getName(),
        );
    }

    private function reverseMaps(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->reverseMaps(...), $value);
        $value = array_reverse($value, true);
        foreach ($value as $key => $item) $value[$key] = $this->reverseMaps($item);
        return $value;
    }
}

/** Test-only mutable authorization target. */
final class SwitchableStorePrintingAuthorization implements AuthorizationCheckerInterface
{
    public bool $granted = true;
    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool { return $this->granted; }
}
