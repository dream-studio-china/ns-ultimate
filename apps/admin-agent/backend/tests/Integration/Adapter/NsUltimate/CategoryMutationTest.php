<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Common\Entity\Category;
use App\Common\Entity\Content;
use App\Common\Entity\Media;
use App\Common\Entity\Picture;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CategoryMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;
    private const URL='/api/v1/manage/agent/operations';
    protected function setUp():void{$this->bootTestDatabase();self::ensureKernelShutdown();}
    protected static function getKernelClass():string{return 'NsUltimate\\Integration\\Backend\\Kernel';}

    public function testCreateUpdateRestoreAndDeleteUseTheOperationLifecycle():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);
        $draft=$this->prepare($client,'create',null,['name'=>'Agent category','slug'=>'agent-category','description'=>'A description','sortOrder'=>2,'enabled'=>true]);
        self::assertSame(['name','slug','description','sortOrder','enabled'],array_column($draft['differences'],'property'));
        $restored=$this->request($client,'GET',self::URL.'/'.$draft['operationId'].'/preview');self::assertSame($draft['differences'],$restored['differences']);
        $receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$id=$receipt['identifier'];$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame('Agent category',$em->find(Category::class,(int)$id)->getName());
        $draft=$this->prepare($client,'update',$id,['name'=>'Renamed category','parent'=>null]);$receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame('Renamed category',$em->find(Category::class,(int)$id)->getName());
        $draft=$this->prepare($client,'delete',$id,[]);self::assertSame(['name','slug','description','parent','sortOrder','enabled'],array_column($draft['differences'],'property'));foreach($draft['differences']as$diff)self::assertNull($diff['after']);$receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertNull($em->find(Category::class,(int)$id));
    }

    public function testInvalidValuesIdsDuplicateSlugAndCyclesAreRejected():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$a=new Category('Existing A','existing-category-a');$b=new Category('Existing B','existing-category-b');$em->persist($a);$em->persist($b);$em->flush();
        foreach ([['name'=>['bad'],'slug'=>'bad'],['name'=>'Valid','slug'=>'duplicate','sortOrder'=>'2'],['name'=>'Valid','slug'=>'bad','enabled'=>1],['name'=>'Valid','slug'=>'bad','parent'=>999999999],['name'=>'Valid','slug'=>'bad','description'=>['bad']],['name'=>'Valid','slug'=>'existing-category-a']] as $changes) $this->assertRejected($client,'create',null,$changes);
        $this->assertRejected($client,'update','1garbage',['name'=>'No']);$this->assertRejected($client,'update','2147483648',['name'=>'No']);$this->assertRejected($client,'create',null,['name'=>'Valid','slug'=>'large-description','description'=>str_repeat('é',32768)]);
        $this->assertRejected($client,'update',(string)$a->getId(),['parent'=>(int)$a->getId()]);
        $em=$client->getContainer()->get(EntityManagerInterface::class);$em->getConnection()->executeStatement('UPDATE common_category SET parent_id = ? WHERE id = ?',[$b->getId(),$a->getId()]);$this->assertRejected($client,'update',(string)$b->getId(),['parent'=>(int)$a->getId()]);
    }

    public function testDeleteRejectsChildrenAndContentAndStaleSnapshotsConflict():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$parent=new Category('Parent protected','parent-protected');$child=new Category('Child protected','child-protected');$child->setParent($parent);$em->persist($parent);$em->persist($child);$content=new Content('Protected content');$content->setCategory($parent);$picture=new Picture('/agent-test-picture',$parent);$media=(new Media('file.bin','file.bin','application/octet-stream',1,'/agent-test-media'))->setCategory($parent);$em->persist($content);$em->persist($picture);$em->persist($media);$em->flush();
        $this->assertRejected($client,'delete',(string)$parent->getId(),[]);
        self::assertSame(1,$em->getRepository(Picture::class)->count(['category'=>$parent->getId()]));self::assertSame(1,$em->getRepository(Media::class)->count(['category'=>$parent->getId()]));
        $free=new Category('Free category','free-category');$em->persist($free);$em->flush();$delete=$this->prepare($client,'delete',(string)$free->getId(),[]);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist((new Content('Newly related content'))->setCategory($em->find(Category::class,$free->getId())));$em->flush();$client->jsonRequest('POST',self::URL.'/'.$delete['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);
        $draft=$this->prepare($client,'update',(string)$child->getId(),['name'=>'Proposed']);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->getConnection()->executeStatement('UPDATE common_category SET name = ? WHERE id = ?', ['Concurrent',$child->getId()]);
        $client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame('Concurrent',$em->find(Category::class,$child->getId())->getName());
    }

    public function testHierarchyIsRevalidatedAtCommit():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$root=new Category('Root category','cycle-root');$target=new Category('Target category','cycle-target');$target->setParent($root);$em->persist($root);$em->persist($target);$em->flush();
        $draft=$this->prepare($client,'update',(string)$target->getId(),['name'=>'Not applied','parent'=>(int)$root->getId()]);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->getConnection()->executeStatement('UPDATE common_category SET parent_id = ? WHERE id = ?',[$target->getId(),$root->getId()]);
        $client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);
    }

    public function testPictureCascadeAndMediaDetachReferencesBlockCategoryDeletion():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$pictureCategory=new Category('Picture category','picture-category');$mediaCategory=new Category('Media category','media-category');$em->persist($pictureCategory);$em->persist($mediaCategory);$em->flush();
        $picture=new Picture('/picture-to-preserve',$pictureCategory);$media=(new Media('preserved.bin','preserved.bin','application/octet-stream',1,'/preserved'))->setCategory($mediaCategory);$em->persist($picture);$em->persist($media);$em->flush();
        $this->assertRejected($client,'delete',(string)$pictureCategory->getId(),[]);$this->assertRejected($client,'delete',(string)$mediaCategory->getId(),[]);
        $em=$client->getContainer()->get(EntityManagerInterface::class);self::assertNotNull($em->find(Picture::class,$picture->getId()));self::assertNotNull($em->find(Media::class,$media->getId()));
        $pictureRaceCategory=new Category('Picture race category','picture-race-category');$mediaRaceCategory=new Category('Media race category','media-race-category');$em->persist($pictureRaceCategory);$em->persist($mediaRaceCategory);$em->flush();
        $pictureDraft=$this->prepare($client,'delete',(string)$pictureRaceCategory->getId(),[]);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist(new Picture('/picture-added-after-preview',$em->find(Category::class,$pictureRaceCategory->getId())));$em->flush();$client->jsonRequest('POST',self::URL.'/'.$pictureDraft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame(1,$em->getRepository(Picture::class)->count(['category'=>$pictureRaceCategory->getId()]));
        $mediaDraft=$this->prepare($client,'delete',(string)$mediaRaceCategory->getId(),[]);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist((new Media('added.bin','added.bin','application/octet-stream',1,'/media-added'))->setCategory($em->find(Category::class,$mediaRaceCategory->getId())));$em->flush();$client->jsonRequest('POST',self::URL.'/'.$mediaDraft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame(1,$em->getRepository(Media::class)->count(['category'=>$mediaRaceCategory->getId()]));
    }

    public function testSlugUniquenessIsRecheckedAtCommit():void
    {
        $client=self::createAuthenticatedClient();$draft=$this->prepare($client,'create',null,['name'=>'Reserved later','slug'=>'claimed-after-preview']);
        $em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist(new Category('Claiming record','claimed-after-preview'));$em->flush();
        $client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);
    }

    public function testNonAdminCannotPrepareCategoryMutation():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$user=(new User())->setEmail('category-agent-reader@example.test')->setUsername('category-agent-reader')->setRoles(['ROLE_USER']);$em->persist($user);$em->flush();$token=$client->getContainer()->get(TokenManager::class)->createAccessToken($user);
        foreach ([['create',null],['update','malformed-target'],['delete','malformed-target']] as [$action,$identifier]) {
            $payload=['entity'=>'Category','action'=>$action,'changes'=>$action==='delete'?[]:['name'=>'Denied','slug'=>'denied-category']];if($identifier!==null)$payload['identifier']=$identifier;
            $client->jsonRequest('POST',self::URL,$payload,['HTTP_AUTHORIZATION'=>'Bearer '.$token]);self::assertResponseStatusCodeSame(403);
        }
    }

    private function prepare(KernelBrowser $client,string $action,?string $id,array $changes):array
    {
        $payload=['entity'=>'Category','action'=>$action,'changes'=>$changes];if($id!==null)$payload['identifier']=$id;
        return $this->request($client,'POST',self::URL,$payload);
    }
    private function commit(KernelBrowser $client,array $draft):array{return $this->request($client,'POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);}
    private function assertRejected(KernelBrowser $client,string $action,?string $id,array $changes):void{$payload=['entity'=>'Category','action'=>$action,'changes'=>$changes];if($id!==null)$payload['identifier']=$id;$client->jsonRequest('POST',self::URL,$payload);self::assertResponseStatusCodeSame(400);}
    private function request(KernelBrowser $client,string $method,string $url,array $payload=[]):array
    {
        if($method==='GET')$client->request($method,$url);else $client->jsonRequest($method,$url,$payload);
        self::assertResponseIsSuccessful();$body=json_decode((string)$client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);return $body['data']??$body;
    }
}
