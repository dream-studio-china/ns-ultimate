<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\ProductPrintingMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductPrintingMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const URL = '/api/v1/manage/agent/operations';

    protected function setUp(): void { $this->bootTestDatabase(); self::ensureKernelShutdown(); }
    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testValidUpdatePreservesOtherProductSettingsIncludingSaleTimes(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, [
            'kitchenPrinterEnable' => false,
            'startSaleTime' => '08:00',
            'endSaleTime' => '22:30',
            'customCategoryNote' => ['keep' => true],
        ], 'preserve');
        $productId = $product->getId();
        $productUuid = $product->getUuid();

        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $productId, 'changes' => ['kitchenPrinterEnable' => true]]);
        self::assertSame((string) $productId, $draft['identifier']);
        self::assertSame($this->title($product), $draft['title']);
        self::assertStringContainsString('FUTURE', $draft['title']);
        self::assertStringContainsString('does not print anything now', $draft['title']);
        self::assertSame([['property' => 'kitchenPrinterEnable', 'before' => false, 'after' => true]], $draft['differences']);
        self::assertSame(['kitchenPrinterEnable' => true], $em->find(AgentOperation::class, $draft['operationId'])->getChanges());
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);

        $em->clear();
        self::assertSame([
            'kitchenPrinterEnable' => true,
            'startSaleTime' => '08:00',
            'endSaleTime' => '22:30',
            'customCategoryNote' => ['keep' => true],
        ], $em->find(Product::class, $productId)->getSettings());
        self::assertSame($store->getId(), $em->find(Product::class, $productId)->getStore()?->getId());
        self::assertSame($productUuid, $em->find(Product::class, $productId)->getUuid());
        self::assertSame(0, $em->getRepository(PrinterJob::class)->count([]));
        self::assertSame(0, $em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    public function testMissingFlagUsesNullBeforeButEffectiveFalseAndGlobalScopeStaysNull(): void
    {
        [$service, $em] = $this->operationService();
        $store = new Store('printing-product-missing', 'Product Store');
        $product = (new Product($store))->setName('Missing flag')->setSettings(['startSaleTime' => '09:00']);
        $global = (new Product())->setName('Global Product')->setSettings(['kitchenPrinterEnable' => false, 'endSaleTime' => '20:00']);
        $em->persist($store);
        $em->persist($product);
        $em->persist($global);
        $em->flush();

        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => $product->getUuid(), 'changes' => ['kitchenPrinterEnable' => true]]);
        self::assertSame([['property' => 'kitchenPrinterEnable', 'before' => null, 'after' => true]], $draft['differences']);
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);

        $globalDraft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $global->getId(), 'changes' => ['kitchenPrinterEnable' => true]]);
        self::assertStringContainsString('Store global', $globalDraft['title']);
        self::assertTrue($service->commit('actor', $globalDraft['operationId'])['verified']);
        $em->clear();
        self::assertSame(['startSaleTime' => '09:00', 'kitchenPrinterEnable' => true], $em->find(Product::class, $product->getId())->getSettings());
        self::assertSame(['kitchenPrinterEnable' => true, 'endSaleTime' => '20:00'], $em->find(Product::class, $global->getId())->getSettings());
        self::assertNull($em->find(Product::class, $global->getId())->getStore());
    }

    public function testSchemaBoundsNoOpsDeletedTargetsAndIdentifierForms(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => true, 'startSaleTime' => '08:00'], 'schema');
        $id = $product->getId();
        foreach ([
            [],
            ['kitchenPrinterEnable' => null],
            ['kitchenPrinterEnable' => 1],
            ['kitchenPrinterEnable' => 'true'],
            ['kitchenPrinterEnable' => true, 'other' => false],
            ['other' => true],
        ] as $changes) {
            try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => $changes]); self::fail('Invalid Product printing change was accepted.'); }
            catch (BadRequestHttpException) { self::assertSame(['kitchenPrinterEnable' => true, 'startSaleTime' => '08:00'], $em->find(Product::class, $id)->getSettings()); }
        }
        try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]); self::fail('Existing true was not treated as an effective no-op.'); }
        catch (BadRequestHttpException) {}
        $disabled = (new Product())->setName('Disabled flag no-op')->setSettings(['kitchenPrinterEnable' => false]);
        $missing = (new Product())->setName('Missing flag no-op');
        $em->persist($disabled);
        $em->persist($missing);
        $em->flush();
        foreach ([$disabled, $missing] as $noopProduct) {
            try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $noopProduct->getId(), 'changes' => ['kitchenPrinterEnable' => false]]); self::fail('Missing or false was not treated as an effective no-op.'); }
            catch (BadRequestHttpException) {}
        }
        foreach (['', '0', 'not-an-id', '2147483648'] as $identifier) {
            try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => $identifier, 'changes' => ['kitchenPrinterEnable' => true]]); self::fail('Invalid Product identifier was accepted.'); }
            catch (BadRequestHttpException) {}
        }
        foreach ([(string) $id, $product->getUuid()] as $identifier) {
            $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => $identifier, 'changes' => ['kitchenPrinterEnable' => false]]);
            self::assertSame([['property' => 'kitchenPrinterEnable', 'before' => true, 'after' => false]], $draft['differences']);
        }
        $deleted = (new Product())->setName('Deleted product');
        $deleted->setIsDeleted(true);
        $em->persist($deleted);
        $em->flush();
        try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $deleted->getId(), 'changes' => ['kitchenPrinterEnable' => true]]); self::fail('Deleted Product was accepted.'); }
        catch (BadRequestHttpException) {}
        foreach ([['action' => 'create', 'identifier' => null, 'changes' => ['kitchenPrinterEnable' => true]], ['action' => 'delete', 'identifier' => (string) $id, 'changes' => []]] as $proposal) {
            try { $service->prepare('actor', ['entity' => 'ProductPrinting', ...$proposal]); self::fail('Non-update Product printing action was accepted.'); }
            catch (BadRequestHttpException) {}
        }
    }

    public function testStoreScopeMustBeActiveAndCategoryScopeIsPreserved(): void
    {
        [$service, $em] = $this->operationService();
        $store = new Store('printing-product-store', 'Product Store');
        $category = (new ProductCategory('Printing category', 'printing-category'))->setStore($store);
        $product = (new Product($store))->setName('Scoped product')->setCategory($category)->setSettings(['kitchenPrinterEnable' => false]);
        $em->persist($store);
        $em->persist($category);
        $em->persist($product);
        $em->flush();

        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $product->getId(), 'changes' => ['kitchenPrinterEnable' => true]]);
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);
        $em->clear();
        $updated = $em->find(Product::class, $product->getId());
        self::assertSame($store->getId(), $updated->getStore()?->getId());
        self::assertSame($category->getId(), $updated->getCategory()?->getId());

        $store = $em->find(Store::class, $store->getId());
        $store->suspend();
        $em->flush();
        try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $product->getId(), 'changes' => ['kitchenPrinterEnable' => false]]); self::fail('Suspended Product Store was accepted.'); }
        catch (BadRequestHttpException) { self::assertTrue($em->find(Product::class, $product->getId())->getSettings()['kitchenPrinterEnable']); }
    }

    public function testMalformedStoredSettingsFailClosedAndSchemaRejectsUnrelatedInvalidSettings(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, null, 'malformed');
        $id = $product->getId();
        foreach (['"invalid"', '[1,2]', '{"kitchenPrinterEnable":"true"}', '{"kitchenPrinterEnable":1}', '{'] as $json) {
            $em->getConnection()->executeStatement('UPDATE trade_product SET settings = ? WHERE id = ?', [$json, $id]);
            $em->clear();
            try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]); self::fail('Malformed stored Product settings were accepted: '.$json); }
            catch (BadRequestHttpException) { self::assertTrue(true); }
        }
        $em->getConnection()->executeStatement('UPDATE trade_product SET settings = ? WHERE id = ?', ['{"kitchenPrinterEnable":false,"startSaleTime":"99:99"}', $id]);
        $em->clear();
        try { $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]); self::fail('Existing invalid ProductSettings schema violation was ignored.'); }
        catch (BadRequestHttpException) { self::assertTrue(true); }
        $em->getConnection()->executeStatement('UPDATE trade_product SET settings = ? WHERE id = ?', ['{"kitchenPrinterEnable":false,"startSaleTime":"08:00","endSaleTime":"21:00","unknown":"keep"}', $id]);
        $em->clear();
        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]);
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);
        $em->clear();
        self::assertSame(['kitchenPrinterEnable' => true, 'startSaleTime' => '08:00', 'endSaleTime' => '21:00', 'unknown' => 'keep'], $em->find(Product::class, $id)->getSettings());
    }

    public function testExpiredOperationCannotBeCommitted(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => false, 'startSaleTime' => '08:00'], 'expiry');
        $id = $product->getId();
        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]);
        $operation = $em->find(AgentOperation::class, $draft['operationId']);
        (new \ReflectionProperty(AgentOperation::class, 'expiresAt'))->setValue($operation, new \DateTimeImmutable('-1 minute'));
        $em->flush();
        try { $service->commit('actor', $draft['operationId']); self::fail('Expired Product printing operation was committed.'); }
        catch (ConflictHttpException) {
            self::assertSame('{"kitchenPrinterEnable":false,"startSaleTime":"08:00"}', $em->getConnection()->fetchOne('SELECT settings FROM trade_product WHERE id = ?', [$id]));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_job'));
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM printer_order_request'));
        }
    }

    public function testRevokedRoleCannotCommit(): void
    {
        $checker = new SwitchableProductPrintingAuthorization();
        [$service, $em] = $this->operationService($checker);
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => false], 'role');
        $id = $product->getId();
        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]);
        $checker->granted = false;
        try { $service->commit('actor', $draft['operationId']); self::fail('Revoked role was allowed to commit Product printing.'); }
        catch (AccessDeniedException) {
            self::assertSame('{"kitchenPrinterEnable":false}', $em->getConnection()->fetchOne('SELECT settings FROM trade_product WHERE id = ?', [$id]));
        }
    }

    public function testExternalProductSettingsChangeConflictsAtCommit(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => false, 'startSaleTime' => '08:00'], 'external');
        $id = $product->getId();
        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]);
        $em->getConnection()->executeStatement('UPDATE trade_product SET settings = ? WHERE id = ?', ['{"kitchenPrinterEnable":false,"startSaleTime":"08:00","external":"changed"}', $id]);
        $em->clear();
        try { $service->commit('actor', $draft['operationId']); self::fail('Changed Product settings were not detected.'); }
        catch (ProposalConflictException) {
            $settings = json_decode((string) $em->getConnection()->fetchOne('SELECT settings FROM trade_product WHERE id = ?', [$id]), true);
            self::assertSame('changed', $settings['external']);
            self::assertFalse($settings['kitchenPrinterEnable']);
        }
    }

    public function testReorderedStoredSettingsCanBeRestoredAndCommitted(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => false, 'startSaleTime' => '08:00'], 'reordered');
        $id = $product->getId();
        $em->getConnection()->executeStatement('UPDATE trade_product SET settings = ? WHERE id = ?', ['{"startSaleTime":"08:00","kitchenPrinterEnable":false}', $id]);
        $em->clear();
        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]);
        $em->getConnection()->executeStatement('UPDATE trade_product SET settings = ? WHERE id = ?', ['{"kitchenPrinterEnable":false,"startSaleTime":"08:00"}', $id]);
        $em->clear();
        self::assertSame($draft['differences'], $service->restore('actor', $draft['operationId'])['differences']);
        self::assertTrue($service->commit('actor', $draft['operationId'])['verified']);
        $em->clear();
        self::assertSame(['kitchenPrinterEnable' => true, 'startSaleTime' => '08:00'], $em->find(Product::class, $id)->getSettings());
    }

    public function testSuspendedStoreConflictsAtCommit(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => false], 'suspended');
        $id = $product->getId();
        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]);
        $store->suspend();
        $em->flush();
        try { $service->commit('actor', $draft['operationId']); self::fail('Suspended Product Store was committed.'); }
        catch (ProposalConflictException) {
            self::assertSame(Store::STATUS_SUSPENDED, $em->getConnection()->fetchOne('SELECT status FROM store WHERE id = ?', [$store->getId()]));
            self::assertFalse(json_decode((string) $em->getConnection()->fetchOne('SELECT settings FROM trade_product WHERE id = ?', [$id]), true)['kitchenPrinterEnable']);
        }
    }

    public function testSoftDeletedProductConflictsAtCommit(): void
    {
        [$service, $em] = $this->operationService();
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => false], 'deleted');
        $id = $product->getId();
        $draft = $service->prepare('actor', ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => (string) $id, 'changes' => ['kitchenPrinterEnable' => true]]);
        $product->setIsDeleted(true);
        $em->flush();
        try { $service->commit('actor', $draft['operationId']); self::fail('Soft-deleted Product was committed.'); }
        catch (ProposalConflictException) {
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT is_deleted FROM trade_product WHERE id = ?', [$id]));
            self::assertFalse(json_decode((string) $em->getConnection()->fetchOne('SELECT settings FROM trade_product WHERE id = ?', [$id]), true)['kitchenPrinterEnable']);
        }
    }

    public function testHttpPrepareRestoreAndCommitUseRegisteredAdapter(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$store, $product] = $this->fixture($em, ['kitchenPrinterEnable' => false, 'startSaleTime' => '08:00'], 'http');
        $client->jsonRequest('POST', self::URL, ['entity' => 'ProductPrinting', 'action' => 'update', 'identifier' => $product->getUuid(), 'changes' => ['kitchenPrinterEnable' => true]]);
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $preview = $preview['data'] ?? $preview;
        self::assertSame('ProductPrinting', $preview['entity']);
        self::assertSame('update', $preview['action']);
        self::assertSame((string) $product->getId(), $preview['identifier']);
        self::assertSame($this->title($product), $preview['title']);
        self::assertSame([['property' => 'kitchenPrinterEnable', 'before' => false, 'after' => true]], $preview['differences']);

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
        $em->clear();
        self::assertSame(['kitchenPrinterEnable' => true, 'startSaleTime' => '08:00'], $em->find(Product::class, $product->getId())->getSettings());
        self::assertSame(0, $em->getRepository(PrinterJob::class)->count([]));
        self::assertSame(0, $em->getRepository(PrinterOrderRequest::class)->count([]));
    }

    /** @return array{0: AgentOperationService, 1: EntityManagerInterface} */
    private function operationService(?SwitchableProductPrintingAuthorization $checker = null): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $checker ??= new SwitchableProductPrintingAuthorization();
        return [new AgentOperationService($em, new MutationAdapterRegistry([new ProductPrintingMutationAdapter($em, $container->get(ValidatorInterface::class), $checker)])), $em];
    }

    /**
     * @param array<string, mixed>|null $settings
     * @return array{0: Store, 1: Product}
     */
    private function fixture(EntityManagerInterface $em, ?array $settings, string $code): array
    {
        $store = new Store('printing-product-'.$code, 'Product Store '.$code);
        $product = (new Product($store))->setName('Product '.$code)->setSettings($settings);
        $em->persist($store);
        $em->persist($product);
        $em->flush();
        return [$store, $product];
    }

    private function title(Product $product): string
    {
        return sprintf(
            '[ProductPrinting; Product #%s; Store %s] Changes FUTURE automatic kitchen-list printing for orders processed after saving; it does not print anything now or create a print job.',
            $product->getId() ?? 'new',
            $product->getStore()?->getUuid() ?? 'global',
        );
    }
}

/** Test-only mutable authorization target. */
final class SwitchableProductPrintingAuthorization implements AuthorizationCheckerInterface
{
    public bool $granted = true;
    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool { return $this->granted; }
}
