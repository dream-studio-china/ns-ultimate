<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Store\Entity\Product;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ProductCategoryMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;
    private const URL='/api/v1/manage/agent/operations';
    protected function setUp():void{$this->bootTestDatabase();self::ensureKernelShutdown();}
    protected static function getKernelClass():string{return 'NsUltimate\\Integration\\Backend\\Kernel';}

    public function testCreateUpdateRestoreAndDeleteInStoreScope():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$store=new Store('agent-category-store','Agent category store');$em->persist($store);$em->flush();
        $parentDraft=$this->prepare($client,'create',null,['name'=>'Agent parent','slug'=>'agent-store-parent','store'=>$store->getId()]);$parentReceipt=$this->commit($client,$parentDraft);self::assertTrue($parentReceipt['verified']);$parentId=$parentReceipt['identifier'];
        $draft=$this->prepare($client,'create',null,['name'=>'Agent child','slug'=>'agent-store-child','store'=>$store->getId(),'parent'=>(int)$parentId,'description'=>'Child description','sortOrder'=>3,'enabled'=>true]);
        self::assertSame(['name','slug','description','parent','sortOrder','enabled','store'],array_column($draft['differences'],'property'));
        $restored=$this->request($client,'GET',self::URL.'/'.$draft['operationId'].'/preview');self::assertSame($draft['differences'],$restored['differences']);$receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$id=$receipt['identifier'];$em=$client->getContainer()->get(EntityManagerInterface::class);$child=$em->find(ProductCategory::class,(int)$id);self::assertSame((int)$parentId,$child->getParent()->getId());self::assertSame($store->getId(),$child->getStore()->getId());
        $draft=$this->prepare($client,'update',(string)$id,['name'=>'Updated child','parent'=>null,'enabled'=>false]);$receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame('Updated child',$em->find(ProductCategory::class,(int)$id)->getName());
        $draft=$this->prepare($client,'delete',(string)$id,[]);self::assertSame(['name','slug','description','parent','sortOrder','enabled','store'],array_column($draft['differences'],'property'));foreach($draft['differences']as$diff)self::assertNull($diff['after']);$receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertNull($em->find(ProductCategory::class,(int)$id));
        // The now-unreferenced parent is independently deletable.
        $draft=$this->prepare($client,'delete',(string)$parentId,[]);self::assertTrue($this->commit($client,$draft)['verified']);
    }

    public function testInvalidTypesStoreUpdatesBadIdsDuplicateSlugsAndParentCyclesAreRejected():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$storeA=new Store('agent-store-type-a','Store A');$storeB=new Store('agent-store-type-b','Store B');$em->persist($storeA);$em->persist($storeB);$em->flush();
        $parent=(new ProductCategory('Parent category','agent-parent'))->setStore($storeA);$child=(new ProductCategory('Child category','agent-child'))->setStore($storeA)->setParent($parent);$em->persist($parent);$em->persist($child);$em->flush();
        foreach ([['name'=>['bad'],'slug'=>'bad'],['name'=>'Valid','slug'=>'bad','enabled'=>1],['name'=>'Valid','slug'=>'bad','sortOrder'=>'1'],['name'=>'Valid','slug'=>'agent-parent','store'=>$storeA->getId()],['name'=>'Valid','slug'=>'bad','store'=>999999999],['name'=>'Valid','slug'=>'bad','parent'=>999999999]] as $changes)$this->assertRejected($client,'create',null,$changes);
        $this->assertRejected($client,'update','1garbage',['name'=>'No']);$this->assertRejected($client,'update',(string)$child->getId(),['store'=>$storeB->getId()]);
        $this->assertRejected($client,'update',(string)$parent->getId(),['parent'=>(int)$child->getId()]);
        $em=$client->getContainer()->get(EntityManagerInterface::class);$storeB=$em->find(Store::class,$storeB->getId());$otherScope=(new ProductCategory('Other scope','agent-other-scope'))->setStore($storeB);$em->persist($otherScope);$em->flush();$this->assertRejected($client,'update',(string)$child->getId(),['parent'=>(int)$otherScope->getId()]);
    }

    public function testDeleteRejectsChildrenProductsAndStaleSnapshot():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$parent=new ProductCategory('Parent category','delete-parent');$child=(new ProductCategory('Child category','delete-child'))->setParent($parent);$em->persist($parent);$em->persist($child);$product=(new Product())->setName('Protected product');$product->setCategory($parent);$em->persist($product);$em->flush();
        $this->assertRejected($client,'delete',(string)$parent->getId(),[]);
        $free=new ProductCategory('Free category','free-product-category');$em->persist($free);$em->flush();$delete=$this->prepare($client,'delete',(string)$free->getId(),[]);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist((new Product())->setName('Newly related product')->setCategory($em->find(ProductCategory::class,$free->getId())));$em->flush();$client->jsonRequest('POST',self::URL.'/'.$delete['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertNotNull($em->find(ProductCategory::class,$free->getId()));self::assertSame(1,$em->getRepository(Product::class)->count(['category'=>$free->getId()]));
        $draft=$this->prepare($client,'update',(string)$child->getId(),['name'=>'Proposed child']);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->getConnection()->executeStatement('UPDATE store_product_category SET name = ? WHERE id = ?', ['Concurrent child',$child->getId()]);$client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame('Concurrent child',$em->find(ProductCategory::class,$child->getId())->getName());
    }

    public function testParentCyclesAreRecheckedAtCommit():void
    {
        // SQLite proves the locked graph is refreshed, not concurrent lock scheduling. The MySQL harness should use two connections: start with B->C and D->A, preview A->B and C->D, let transaction one lock/commit A->B while transaction two waits on the ordered graph lock, then require transaction two to see A->B->C and reject C->D as a cycle.
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$root=new ProductCategory('Root category','cycle-product-root');$target=(new ProductCategory('Target category','cycle-product-target'))->setParent($root);$em->persist($root);$em->persist($target);$em->flush();
        $draft=$this->prepare($client,'update',(string)$target->getId(),['name'=>'Not applied','parent'=>(int)$root->getId()]);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->getConnection()->executeStatement('UPDATE store_product_category SET parent_id = ? WHERE id = ?',[$target->getId(),$root->getId()]);
        $client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);
    }

    public function testScopedSlugUniquenessIsRecheckedAtCommit():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$store=new Store('agent-slug-scope','Slug scope');$em->persist($store);$em->flush();
        $draft=$this->prepare($client,'create',null,['name'=>'Proposed','slug'=>'claimed-store-slug','store'=>$store->getId()]);
        $em=$client->getContainer()->get(EntityManagerInterface::class);$store=$em->find(Store::class,$store->getId());$em->persist((new ProductCategory('Claiming category','claimed-store-slug'))->setStore($store));$em->flush();
        $client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);
    }

    public function testAuthorizationCoversAllActionsAndRevocationBeforeCommit():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$user=(new \App\Identity\Entity\User())->setEmail('product-category-reader@example.test')->setUsername('product-category-reader')->setRoles(['ROLE_USER']);$em->persist($user);$em->flush();$token=$client->getContainer()->get(\App\Identity\Security\TokenManager::class)->createAccessToken($user);
        foreach([['create',null],['update','malformed'],['delete','malformed']]as[$action,$id]){$payload=['entity'=>'ProductCategory','action'=>$action,'changes'=>$action==='delete'?[]:['name'=>'Denied','slug'=>'denied-product-category']];if($id!==null)$payload['identifier']=$id;$client->jsonRequest('POST',self::URL,$payload,['HTTP_AUTHORIZATION'=>'Bearer '.$token]);self::assertResponseStatusCodeSame(403);}
        $actor=(new \App\Identity\Entity\User())->setEmail('product-category-revoked-admin@example.test')->setUsername('product-category-revoked-admin')->setRoles(['ROLE_ADMIN']);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist($actor);$em->flush();$token=$client->getContainer()->get(\App\Identity\Security\TokenManager::class)->createAccessToken($actor);
        $client->jsonRequest('POST',self::URL,['entity'=>'ProductCategory','action'=>'create','changes'=>['name'=>'Revoked','slug'=>'revoked-product-category']],['HTTP_AUTHORIZATION'=>'Bearer '.$token]);self::assertResponseIsSuccessful();$preview=json_decode((string)$client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);$preview=$preview['data']??$preview;
        $em=$client->getContainer()->get(EntityManagerInterface::class);$em->find(\App\Identity\Entity\User::class,$actor->getId())->setRoles(['ROLE_USER']);$em->flush();$client->jsonRequest('POST',self::URL.'/'.$preview['operationId'].'/commit',['confirmed'=>true],['HTTP_AUTHORIZATION'=>'Bearer '.$token]);self::assertResponseStatusCodeSame(403);
    }

    private function prepare(KernelBrowser $client,string $action,?string $id,array $changes):array{$p=['entity'=>'ProductCategory','action'=>$action,'changes'=>$changes];if($id!==null)$p['identifier']=$id;return $this->request($client,'POST',self::URL,$p);}
    private function commit(KernelBrowser $client,array $draft):array{return $this->request($client,'POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);}
    private function assertRejected(KernelBrowser $client,string $action,?string $id,array $changes):void{$p=['entity'=>'ProductCategory','action'=>$action,'changes'=>$changes];if($id!==null)$p['identifier']=$id;$client->jsonRequest('POST',self::URL,$p);self::assertResponseStatusCodeSame(400);}
    private function request(KernelBrowser $client,string $method,string $url,array $payload=[]):array{if($method==='GET')$client->request($method,$url);else $client->jsonRequest($method,$url,$payload);self::assertResponseIsSuccessful();$body=json_decode((string)$client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);return $body['data']??$body;}
}
