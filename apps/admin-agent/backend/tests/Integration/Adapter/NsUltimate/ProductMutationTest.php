<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\ProductMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void { $this->bootTestDatabase(); self::ensureKernelShutdown(); }
    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testOperationServiceCreatesCanonicalProductAndRestoresSamePreview(): void
    {
        [$service, $em] = $this->operationService();
        $store = new Store('agent-product', 'Agent Product Store'); $em->persist($store); $em->flush();
        $draft = $service->prepare('agent-test', ['entity'=>'Product','action'=>'create','changes'=>[
            'isSoldout'=>true,'status'=>'inactive','recommendationLevel'=>4,'sortOrder'=>-3,'name'=>'Agent Item','store'=>$store->getUuid(),'description'=>'Plain text',
        ]]);
        self::assertSame(['name','description','store','sortOrder','recommendationLevel','status','isSoldout'], array_column($draft['differences'],'property'));
        self::assertSame($store->getUuid(),$draft['differences'][2]['after']);
        self::assertStringStartsWith('[Product #new; Store '.$store->getUuid().'] ', $draft['title']);
        $em->clear();
        self::assertSame(['name','description','store','sortOrder','recommendationLevel','status','isSoldout'], array_keys($em->getRepository(\NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation::class)->find($draft['operationId'])->getChanges()));
        self::assertSame($draft['differences'], $service->restore('agent-test', $draft['operationId'])['differences']);
        $receipt = $service->commit('agent-test', $draft['operationId']);
        self::assertTrue($receipt['verified']);
        $product = $em->find(Product::class, (int)$receipt['identifier']);
        self::assertInstanceOf(Product::class, $product);
        self::assertSame('Agent Item', $product->getName()); self::assertSame($store->getId(), $product->getStore()?->getId());
        self::assertSame(-3, $product->getSortOrder()); self::assertSame(4, $product->getRecommendationLevel());
        self::assertSame('inactive', $product->getStatus()); self::assertTrue($product->isSoldout());
        $update=$service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>['name'=>'Updated Item','isSoldout'=>false]]);
        self::assertSame(['name','isSoldout'],array_column($update['differences'],'property'));
        self::assertTrue($service->commit('agent-test',$update['operationId'])['verified']);
        self::assertSame('Updated Item',$em->find(Product::class,$product->getId())->getName());
        self::assertFalse($em->find(Product::class,$product->getId())->isSoldout());
        try { $service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>['name'=>'Updated Item']]); self::fail('No-op update was accepted.'); }
        catch (BadRequestHttpException) { self::assertSame('Updated Item',$product->getName()); }
        $global=$service->prepare('agent-test',['entity'=>'Product','action'=>'create','changes'=>['name'=>'Global Item']]);
        self::assertSame(['name'=>'Global Item','store'=>null],$em->getRepository(\NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation::class)->find($global['operationId'])->getChanges());
        self::assertNull($global['differences'][1]['after']);
        self::assertStringStartsWith('[Product #new; Store global] ', $global['title']);
        $globalReceipt=$service->commit('agent-test',$global['operationId']);
        self::assertTrue($globalReceipt['verified']);
        self::assertNull($em->find(Product::class,(int)$globalReceipt['identifier'])->getStore());
    }

    public function testUpdateEnforcesScopeTypesBoundsRoleAndStaleSnapshots(): void
    {
        [$service, $em, $authorization] = $this->operationService(true);
        $store = new Store('agent-update', 'Agent Update Store'); $em->persist($store); $em->flush();
        $product=(new Product($store))->setName('Before'); $em->persist($product); $em->flush();
        foreach ([['sortOrder'=>2147483648],['recommendationLevel'=>-2147483649],['isSoldout'=>1],['name'=>str_repeat('x',256)],['description'=>str_repeat('x',65536)],['store'=>$store->getUuid()],['status'=>'deleted'],['metadata'=>['unsafe'=>true]],['price'=>100]] as $invalid) {
            try { $service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>$invalid]); self::fail('Invalid Product proposal was accepted.'); }
            catch (BadRequestHttpException) { self::assertSame('Before',$product->getName()); }
        }
        $deny = $this->createStub(AuthorizationCheckerInterface::class); $deny->method('isGranted')->willReturn(false);
        $deniedService = new AgentOperationService($em,new MutationAdapterRegistry([new ProductMutationAdapter($em,self::getContainer()->get(ValidatorInterface::class),$deny)]),\NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::legacyPolicy());
        foreach ([['action'=>'update','identifier'=>'999999999','changes'=>['name'=>'X']],['action'=>'create','changes'=>['name'=>'New','store'=>$store->getUuid()]],['action'=>'delete','identifier'=>'999999999']] as $proposal) {
            try { $deniedService->prepare('agent-test',['entity'=>'Product',...$proposal]); self::fail('Non-admin was allowed.'); }
            catch (AccessDeniedException) { self::assertTrue(true); }
        }
        try { $service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>'not-an-id','changes'=>['name'=>'X']]); self::fail('Invalid identifier was accepted.'); }
        catch (BadRequestHttpException) { self::assertSame('Before',$product->getName()); }
        $draft=$service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>['name'=>'Proposed']]);
        $product->setName('Concurrent'); $em->flush();
        try { $service->commit('agent-test',$draft['operationId']); self::fail('Stale proposal was committed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertSame('Concurrent',$product->getName()); }
    }

    public function testCategoryMustBeEnabledAndInExactStoreScope(): void
    {
        [$service,$em]=$this->operationService();
        $storeA=new Store('agent-a','Store A'); $storeB=new Store('agent-b','Store B'); $em->persist($storeA);$em->persist($storeB);$em->flush();
        $category=(new ProductCategory('Category','category'))->setStore($storeA); $em->persist($category); $em->flush();
        $product=(new Product($storeA))->setName('Scoped');$em->persist($product);$em->flush();
        $draft=$service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>['category'=>$category->getId()]]);
        self::assertNotEmpty($draft['differences']);
        $other=(new Product($storeB))->setName('Other');$em->persist($other);$em->flush();
        foreach ([['product'=>$other,'category'=>$category],['product'=>$product,'category'=>null]] as $case) {
            if ($case['category'] === null) { $category->setEnabled(false); $em->flush(); }
            try { $service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$case['product']->getId(),'changes'=>['category'=>$category->getId()]]); self::fail('Invalid category scope was accepted.'); }
            catch (BadRequestHttpException) { self::assertNull($case['product']->getCategory()); }
        }
        try { $service->commit('agent-test',$draft['operationId']); self::fail('Category enabled-state change was not detected.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertFalse($category->isEnabled()); }
    }

    public function testDeletedProductTargetConflictsAtCommit(): void
    {
        [$service,$em]=$this->operationService(); $product=(new Product())->setName('Will disappear');$em->persist($product);$em->flush();
        $draft=$service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>['name'=>'Proposed']]);
        $id=$product->getId();$em->remove($product);$em->flush();
        try { $service->commit('agent-test',$draft['operationId']); self::fail('Deleted Product target was committed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertSame(0,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM trade_product WHERE id = ?',[$id])); }
    }

    public function testSoftDeletedProductConflictsAtCommit(): void
    {
        [$service,$em]=$this->operationService();$product=(new Product())->setName('Soft deleted');$em->persist($product);$em->flush();
        $draft=$service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>['name'=>'Proposed']]);
        $product->setIsDeleted(true);$em->flush();
        try { $service->commit('agent-test',$draft['operationId']); self::fail('Soft-deleted Product change was committed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertTrue($product->getIsDeleted()); }
    }

    public function testRelatedStoreStateIsRefreshedAndFingerprintChecked(): void
    {
        [$service,$em]=$this->operationService();$store=new Store('store-state','Store state');$em->persist($store);$em->flush();
        $product=(new Product($store))->setName('Store bound');$em->persist($product);$em->flush();
        $draft=$service->prepare('agent-test',['entity'=>'Product','action'=>'update','identifier'=>(string)$product->getId(),'changes'=>['name'=>'Changed']]);
        $store->suspend();$em->flush();
        try { $service->commit('agent-test',$draft['operationId']); self::fail('Changed Store scope snapshot was committed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertSame(Store::STATUS_SUSPENDED,$store->getStatus()); }
    }

    private function operationService(bool $withChecker=false): array
    {
        $container=self::getContainer(); $em=$container->get(EntityManagerInterface::class);
        $checker=$this->createStub(AuthorizationCheckerInterface::class); $checker->method('isGranted')->willReturn(true);
        $adapter=new ProductMutationAdapter($em,$container->get(ValidatorInterface::class),$checker);
        $service=new AgentOperationService($em,new MutationAdapterRegistry([$adapter]),\NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::legacyPolicy());
        return $withChecker?[$service,$em,$checker]:[$service,$em];
    }
}
