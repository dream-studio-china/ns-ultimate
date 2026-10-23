<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Common\Entity\Content;
use App\Common\Entity\Tag;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Common\Service\TagWriteService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class TagMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;
    private const URL='/api/v1/manage/agent/operations';
    protected function setUp():void{$this->bootTestDatabase();self::ensureKernelShutdown();}
    protected static function getKernelClass():string{return 'NsUltimate\\Integration\\Backend\\Kernel';}

    public function testCreateUpdateRestoreAndDeleteTags():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);
        $draft=$this->prepare($client,'create',null,['name'=>'Color tag','slug'=>'agent-color-tag','color'=>'#A1b2C3']);self::assertSame(['name','slug','color'],array_column($draft['differences'],'property'));
        $restored=$this->request($client,'GET',self::URL.'/'.$draft['operationId'].'/preview');self::assertSame($draft['differences'],$restored['differences']);
        $receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$id=$receipt['identifier'];$em=$client->getContainer()->get(EntityManagerInterface::class);$tag=$em->find(Tag::class,(int)$id);self::assertSame('#A1b2C3',$tag->getColor());
        $draft=$this->prepare($client,'update',(string)$id,['name'=>'Updated tag','color'=>null]);$receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertNull($em->find(Tag::class,(int)$id)->getColor());
        $draft=$this->prepare($client,'delete',(string)$id,[]);self::assertSame(['name','slug','color'],array_column($draft['differences'],'property'));foreach($draft['differences']as$diff)self::assertNull($diff['after']);$receipt=$this->commit($client,$draft);self::assertTrue($receipt['verified']);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertNull($em->find(Tag::class,(int)$id));
    }

    public function testInvalidFieldsSlugTypesAndColorAreRejected():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$existing=new Tag('Existing tag','existing-agent-tag');$em->persist($existing);$em->flush();
        foreach ([['name'=>['bad'],'slug'=>'tag'],['name'=>'Tag','slug'=>'slug','color'=>'red'],['name'=>'Tag','slug'=>'slug','color'=>1],['name'=>'Tag','slug'=>'slug','unexpected'=>true],['name'=>'Other','slug'=>'existing-agent-tag']] as $changes)$this->assertRejected($client,'create',null,$changes);
        $this->assertRejected($client,'update','1garbage',['name'=>'No']);
        $this->assertRejected($client,'update',(string)$existing->getId(),['name'=>'Existing tag']);
    }

    public function testDeleteRejectsAssociationsAndStaleSnapshotsConflict():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$tag=new Tag('Attached tag','attached-agent-tag');$content=new Content('Attached content');$content->addTag($tag);$em->persist($tag);$em->persist($content);$em->flush();
        $this->assertRejected($client,'delete',(string)$tag->getId(),[]);
        $free=new Tag('Free tag','free-agent-tag');$em->persist($free);$em->flush();$draft=$this->prepare($client,'delete',(string)$free->getId(),[]);$em=$client->getContainer()->get(EntityManagerInterface::class);$content=new Content('Newly attached content');$content->addTag($em->find(Tag::class,$free->getId()));$em->persist($content);$em->flush();$client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertNotNull($em->find(Tag::class,$free->getId()));self::assertSame(1,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM common_content_tag WHERE tag_id = ?',[$free->getId()]));
        $draft=$this->prepare($client,'update',(string)$tag->getId(),['name'=>'Proposed tag']);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->getConnection()->executeStatement('UPDATE common_tag SET name = ? WHERE id = ?', ['Concurrent tag',$tag->getId()]);$client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);$em=$client->getContainer()->get(EntityManagerInterface::class);self::assertSame('Concurrent tag',$em->find(Tag::class,$tag->getId())->getName());
    }

    public function testSlugUniquenessIsRecheckedAtCommit():void
    {
        $client=self::createAuthenticatedClient();$draft=$this->prepare($client,'create',null,['color'=>null,'slug'=>'claimed-tag-after-preview','name'=>'Proposed tag']);
        $em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist(new Tag('Claiming tag','claimed-tag-after-preview'));$em->flush();
        $client->jsonRequest('POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);self::assertResponseStatusCodeSame(409);
    }

    public function testAuthorizationCoversAllActionsAndRevocationBeforeCommit():void
    {
        $client=self::createAuthenticatedClient();$em=$client->getContainer()->get(EntityManagerInterface::class);$user=(new \App\Identity\Entity\User())->setEmail('tag-non-admin@example.test')->setUsername('tag-non-admin')->setRoles(['ROLE_USER']);$em->persist($user);$em->flush();$token=$client->getContainer()->get(\App\Identity\Security\TokenManager::class)->createAccessToken($user);
        foreach([['create',null],['update','malformed'],['delete','malformed']]as[$action,$id]){$payload=['entity'=>'Tag','action'=>$action,'changes'=>$action==='delete'?[]:['name'=>'Denied','slug'=>'denied-tag']];if($id!==null)$payload['identifier']=$id;$client->jsonRequest('POST',self::URL,$payload,['HTTP_AUTHORIZATION'=>'Bearer '.$token]);self::assertResponseStatusCodeSame(403);}
        $actor=(new \App\Identity\Entity\User())->setEmail('tag-revoked-admin@example.test')->setUsername('tag-revoked-admin')->setRoles(['ROLE_ADMIN']);$em=$client->getContainer()->get(EntityManagerInterface::class);$em->persist($actor);$em->flush();$token=$client->getContainer()->get(\App\Identity\Security\TokenManager::class)->createAccessToken($actor);
        $client->jsonRequest('POST',self::URL,['entity'=>'Tag','action'=>'create','changes'=>['name'=>'Revoked','slug'=>'revoked-tag']],['HTTP_AUTHORIZATION'=>'Bearer '.$token]);self::assertResponseIsSuccessful();$preview=json_decode((string)$client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);$preview=$preview['data']??$preview;
        $em=$client->getContainer()->get(EntityManagerInterface::class);$em->find(\App\Identity\Entity\User::class,$actor->getId())->setRoles(['ROLE_USER']);$em->flush();$client->jsonRequest('POST',self::URL.'/'.$preview['operationId'].'/commit',['confirmed'=>true],['HTTP_AUTHORIZATION'=>'Bearer '.$token]);self::assertResponseStatusCodeSame(403);
    }

    public function testAdapterUsesSharedDomainFieldSchemaAndNormalization():void
    {
        $client=self::createAuthenticatedClient();
        $service=$client->getContainer()->get(TagWriteService::class);
        self::assertSame(['name','slug','color'],array_keys(TagWriteService::fieldSchema('create')));
        $changes=['name'=>' Shared tag ','slug'=>'shared-adapter-tag','color'=>'#AbCdEf'];
        $draft=$this->prepare($client,'create',null,$changes);
        self::assertSame($service->normalize('create',$changes), array_column($draft['differences'],'after','property'));
        $receipt=$this->commit($client,$draft);
        self::assertTrue($receipt['verified']);
        $em=$client->getContainer()->get(EntityManagerInterface::class);
        $tag=$em->find(Tag::class,(int)$receipt['identifier']);
        self::assertSame(' Shared tag ',$tag->getName());
    }

    private function prepare(KernelBrowser $client,string $action,?string $id,array $changes):array{$p=['entity'=>'Tag','action'=>$action,'changes'=>$changes];if($id!==null)$p['identifier']=$id;return $this->request($client,'POST',self::URL,$p);}
    private function commit(KernelBrowser $client,array $draft):array{return $this->request($client,'POST',self::URL.'/'.$draft['operationId'].'/commit',['confirmed'=>true]);}
    private function assertRejected(KernelBrowser $client,string $action,?string $id,array $changes):void{$p=['entity'=>'Tag','action'=>$action,'changes'=>$changes];if($id!==null)$p['identifier']=$id;$client->jsonRequest('POST',self::URL,$p);self::assertResponseStatusCodeSame(400);}
    private function request(KernelBrowser $client,string $method,string $url,array $payload=[]):array{if($method==='GET')$client->request($method,$url);else $client->jsonRequest($method,$url,$payload);self::assertResponseIsSuccessful();$body=json_decode((string)$client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);return $body['data']??$body;}
}
