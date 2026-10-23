<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\SpecificationMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SpecificationMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;
    protected function setUp(): void { $this->bootTestDatabase(); self::ensureKernelShutdown(); }
    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testCreateUpdateAndDeleteUseCanonicalParentScopeAndReadBack(): void
    {
        [$service,$em]=$this->operationService(); $product=$this->product($em);
        $create=$service->prepare('spec-test',['entity'=>'Specification','action'=>'create','changes'=>['sort'=>2,'status'=>'inactive','price'=>2147483647,'name'=>'SKU','productId'=>$product->getId()]]);
        self::assertStringStartsWith('[Product #'.$product->getId().'; Specification #new; Store global] ', $create['title']);
        $em->clear();
        $stored=$em->getRepository(\NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation::class)->find($create['operationId'])->getChanges();
        self::assertSame(['productId','name','price','status','sort'],array_keys($stored));
        self::assertSame(['productId','name','price','status','sort'],array_column($create['differences'],'property'));
        self::assertSame($create['differences'],$service->restore('spec-test',$create['operationId'])['differences']);
        $created=$service->commit('spec-test',$create['operationId']); self::assertTrue($created['verified']);
        $spec=$em->find(Specification::class,(int)$created['identifier']); self::assertInstanceOf(Specification::class,$spec);
        self::assertSame($product->getId(),$spec->getProduct()->getId()); self::assertSame(2147483647,$spec->getPrice());

        $update=$service->prepare('spec-test',['entity'=>'Specification','action'=>'update','identifier'=>(string)$spec->getId(),'changes'=>['productId'=>$product->getId(),'price'=>2147483647,'name'=>'SKU Updated']]);
        self::assertStringStartsWith('[Product #'.$product->getId().'; Specification #'.$spec->getId().'; Store global] ', $update['title']);
        self::assertSame(['productId','name','price'],array_keys($em->getRepository(\NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation::class)->find($update['operationId'])->getChanges()));
        self::assertSame($update['differences'],$service->restore('spec-test',$update['operationId'])['differences']);
        self::assertTrue($service->commit('spec-test',$update['operationId'])['verified']);
        self::assertSame(2147483647,$em->find(Specification::class,(int)$created['identifier'])->getPrice());
        try { $service->prepare('spec-test',['entity'=>'Specification','action'=>'update','identifier'=>(string)$spec->getId(),'changes'=>['productId'=>$product->getId()]]); self::fail('No-op Specification update was accepted.'); }
        catch (BadRequestHttpException) { self::assertSame(2147483647,$spec->getPrice()); }
    }

    public function testPriceAndFieldTypesAreBoundedAndParentCannotMove(): void
    {
        [$service,$em,$authorization]=$this->operationService(true); $product=$this->product($em); $other=$this->product($em,'Other');
        $spec=(new Specification())->setProduct($product)->setName('Bounded')->setPrice(20);$em->persist($spec);$em->flush();
        $countBefore=$em->getRepository(Specification::class)->count([]);
        foreach ([['price'=>-1],['price'=>2147483648],['price'=>'20'],['price'=>null],['name'=>str_repeat('n',256)],['name'=>'<b>HTML</b>'],['sort'=>2147483648],['status'=>true],['productId'=>$other->getId()],['productId'=>2147483648],['metadata'=>[]]] as $invalid) {
            try { $service->prepare('spec-test',['entity'=>'Specification','action'=>'update','identifier'=>(string)$spec->getId(),'changes'=>['productId'=>$product->getId(),...$invalid]]); self::fail('Invalid Specification proposal was accepted.'); }
            catch (BadRequestHttpException) { self::assertSame('Bounded',$spec->getName()); self::assertSame($product->getId(),$spec->getProduct()->getId()); }
        }
        try { $service->prepare('spec-test',['entity'=>'Specification','action'=>'create','changes'=>['productId'=>$product->getId(),'name'=>'Too large','price'=>2147483648]]); self::fail('Out-of-range price was accepted.'); }
        catch (BadRequestHttpException) { self::assertSame($countBefore,$em->getRepository(Specification::class)->count([])); }
        $deny=$this->createStub(AuthorizationCheckerInterface::class);$deny->method('isGranted')->willReturn(false);
        $deniedService=new AgentOperationService($em,new MutationAdapterRegistry([new SpecificationMutationAdapter($em,$deny)]),\NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::legacyPolicy());
        foreach ([['action'=>'create','changes'=>['productId'=>$product->getId(),'name'=>'X','price'=>1]],['action'=>'update','identifier'=>'999999','changes'=>['productId'=>$product->getId(),'name'=>'X']]] as $proposal) {
            try { $deniedService->prepare('spec-test',['entity'=>'Specification',...$proposal]); self::fail('Non-admin was allowed.'); }
            catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) { self::assertTrue(true); }
        }
    }

    public function testStaleSnapshotConflicts(): void
    {
        [$service,$em]=$this->operationService(); $product=$this->product($em);
        $spec=(new Specification())->setProduct($product)->setName('Referenced')->setPrice(10);$em->persist($spec);$em->flush();
        $draft=$service->prepare('spec-test',['entity'=>'Specification','action'=>'update','identifier'=>(string)$spec->getId(),'changes'=>['productId'=>$product->getId(),'name'=>'Proposed']]);
        $spec->setSort(9);$em->flush();
        try { $service->commit('spec-test',$draft['operationId']); self::fail('Stale proposal was committed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertSame(9,$spec->getSort()); }
    }

    public function testDeleteIsBlockedBeforeLookupAndDoesNotMutateSpecification(): void
    {
        [$service,$em]=$this->operationService(); $product=$this->product($em);
        $spec=(new Specification())->setProduct($product)->setName('Referenced')->setPrice(10);$em->persist($spec);$em->flush();
        foreach ([['identifier'=>(string)$spec->getId()],['identifier'=>'2147483000'],['identifier'=>(string)$spec->getId(),'changes'=>['productId'=>$product->getId()]]] as $input) {
            try { $service->prepare('spec-test',['entity'=>'Specification','action'=>'delete',...$input]); self::fail('Specification deletion was accepted.'); }
            catch (BadRequestHttpException) { self::assertSame('Referenced',$em->find(Specification::class,$spec->getId())->getName()); }
        }
    }

    public function testDeletedSpecificationTargetConflictsAtCommit(): void
    {
        [$service,$em]=$this->operationService();$product=$this->product($em);$spec=(new Specification())->setProduct($product)->setName('Will disappear')->setPrice(1);$em->persist($spec);$em->flush();
        $draft=$service->prepare('spec-test',['entity'=>'Specification','action'=>'update','identifier'=>(string)$spec->getId(),'changes'=>['productId'=>$product->getId(),'name'=>'Proposed']]);
        $id=$spec->getId();$em->remove($spec);$em->flush();
        try { $service->commit('spec-test',$draft['operationId']); self::fail('Deleted Specification target was committed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertSame(0,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM trade_specification WHERE id = ?',[$id])); }
    }

    public function testCreateRequiresExistingLiveParentAndConflictsWhenParentChanges(): void
    {
        [$service,$em]=$this->operationService();$product=$this->product($em);
        $countBefore=$em->getRepository(Specification::class)->count([]);
        try { $service->prepare('spec-test',['entity'=>'Specification','action'=>'create','changes'=>['productId'=>2147483000,'name'=>'Missing parent','price'=>1]]); self::fail('Missing parent was accepted.'); }
        catch (BadRequestHttpException) { self::assertSame($countBefore,$em->getRepository(Specification::class)->count([])); }
        $draft=$service->prepare('spec-test',['entity'=>'Specification','action'=>'create','changes'=>['productId'=>$product->getId(),'name'=>'Parent fingerprint','price'=>1]]);
        $matchingBefore=(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM trade_specification WHERE name = ?', ['Parent fingerprint']);
        $product->setStatus(Product::STATUS_INACTIVE);$em->flush();
        try { $service->commit('spec-test',$draft['operationId']); self::fail('Changed parent snapshot was committed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertSame($matchingBefore,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM trade_specification WHERE name = ?', ['Parent fingerprint'])); }
    }

    public function testCreateUnderSoftDeletedProductIsRejected(): void
    {
        [$service,$em]=$this->operationService();$product=$this->product($em);$product->setIsDeleted(true);$em->flush();
        $countBefore=$em->getRepository(Specification::class)->count([]);
        try { $service->prepare('spec-test',['entity'=>'Specification','action'=>'create','changes'=>['productId'=>$product->getId(),'name'=>'Deleted parent','price'=>1]]); self::fail('Deleted parent was accepted.'); }
        catch (BadRequestHttpException) { self::assertSame($countBefore,$em->getRepository(Specification::class)->count([])); }
    }

    public function testStaticScopeTitlePrefixSurvivesNameCollisionAndMaximumUnicodeName(): void
    {
        [$service,$em]=$this->operationService();$product=$this->product($em,'Parent #99 size');
        $name='Product #5 size '.str_repeat('é',238);
        self::assertSame(254,mb_strlen($name));
        $draft=$service->prepare('spec-test',['entity'=>'Specification','action'=>'create','changes'=>['productId'=>$product->getId(),'name'=>$name,'price'=>0]]);
        self::assertStringStartsWith('[Product #'.$product->getId().'; Specification #new; Store global] '.$name,$draft['title']);
        self::assertSame($draft['differences'],$service->restore('spec-test',$draft['operationId'])['differences']);
    }

    public function testCreateConflictsWhenParentStoreScopeMoves(): void
    {
        [$service,$em]=$this->operationService();$storeA=new Store('scope-a','Scope A');$storeB=new Store('scope-b','Scope B');$em->persist($storeA);$em->persist($storeB);$em->flush();
        $product=(new Product($storeA))->setName('Scoped parent');$em->persist($product);$em->flush();
        $draft=$service->prepare('spec-test',['entity'=>'Specification','action'=>'create','changes'=>['productId'=>$product->getId(),'name'=>'Scoped SKU','price'=>100]]);
        self::assertStringContainsString($storeA->getUuid(),$draft['title']);
        $product->setStore($storeB);$em->flush();
        try { $service->commit('spec-test',$draft['operationId']); self::fail('Parent store scope change was not detected.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertSame(0,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM trade_specification WHERE name = ?', ['Scoped SKU'])); }
    }

    public function testUpdateConflictsWhenParentIsSoftDeleted(): void
    {
        [$service,$em]=$this->operationService();$product=$this->product($em);$spec=(new Specification())->setProduct($product)->setName('Child')->setPrice(1);$em->persist($spec);$em->flush();
        $draft=$service->prepare('spec-test',['entity'=>'Specification','action'=>'update','identifier'=>(string)$spec->getId(),'changes'=>['productId'=>$product->getId(),'name'=>'Updated']]);
        $product->setIsDeleted(true);$em->flush();
        try { $service->commit('spec-test',$draft['operationId']); self::fail('Soft-deleted parent was allowed.'); }
        catch (\NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException) { self::assertTrue($product->getIsDeleted()); }
    }

    private function product(EntityManagerInterface $em,string $name='Parent'): Product { $p=(new Product())->setName($name);$em->persist($p);$em->flush();return $p; }
    private function operationService(bool $withChecker=false): array
    {
        $container=self::getContainer();$em=$container->get(EntityManagerInterface::class);$checker=$this->createStub(AuthorizationCheckerInterface::class);$checker->method('isGranted')->willReturn(true);
        $adapter=new SpecificationMutationAdapter($em,$checker);$service=new AgentOperationService($em,new MutationAdapterRegistry([$adapter]),\NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Support\AdminConfigurationTestManifest::legacyPolicy());
        return $withChecker?[$service,$em,$checker]:[$service,$em];
    }
}
