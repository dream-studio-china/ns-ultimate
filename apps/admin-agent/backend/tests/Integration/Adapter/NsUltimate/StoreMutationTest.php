<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class StoreMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;
    private const URL = '/api/v1/manage/agent/operations';
    protected function setUp(): void { $this->bootTestDatabase(); self::ensureKernelShutdown(); }
    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testUpdatePreviewsOnlyProposedContactAndAddressFields(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = (new Store('private-code', 'Public Name'))->setContact(['phone'=>'13800138000', 'email'=>'private@example.test'])->setAddress(['city'=>'Old City', 'detail'=>'private room']);
        $em->persist($store); $em->flush();
        $preview = $this->post($client, ['action'=>'update', 'identifier'=>$store->getUuid(), 'changes'=>['name'=>'New Name', 'address'=>['city'=>'New City']]]);
        self::assertSame(['city'=>'New City'], $preview['differences'][1]['after']);
        $text = json_encode($preview, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private@example.test', $text);
        self::assertStringNotContainsString('private room', $text);
        self::assertStringNotContainsString('private-code', $text);
        self::assertStringNotContainsString('Old City', $text);
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed'=>true]);
        self::assertResponseIsSuccessful();
        $em->clear();
        $updated = $em->find(Store::class, $store->getId());
        self::assertSame('New Name', $updated->getName());
        self::assertSame('13800138000', $updated->getContact()['phone']);
        self::assertSame('private@example.test', $updated->getContact()['email']);
        self::assertSame('New City', $updated->getAddress()['city']);
        self::assertSame('private room', $updated->getAddress()['detail']);
    }

    public function testRejectsStoreLifecycleAndInvalidNestedFields(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = new Store('store-validation', 'Stable'); $em->persist($store); $em->flush();
        foreach ([
            ['status'=>'closed'], ['timezone'=>'Pacific/Auckland'], ['currency'=>'USD'], ['settings'=>['order'=>['requireAcceptance'=>false]]],
            ['contact'=>['unknown'=>'value']], ['contact'=>['email'=>['nested'=>'injection']]],
            ['contact'=>['managerUserUuid'=>'------------------------------------']],
            ['address'=>['latitude'=>1.0]], ['address'=>['detail'=>str_repeat('x', 256)]],
        ] as $changes) {
            $client->jsonRequest('POST', self::URL, ['entity'=>'Store','action'=>'update','identifier'=>$store->getUuid(),'changes'=>$changes]);
            self::assertResponseStatusCodeSame(400);
        }
        self::assertSame('Stable', $em->find(Store::class, $store->getId())->getName());
    }

    public function testStoreFieldsUseDeclaredOrderAndPartialContactNoOpIsRejected(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = (new Store('field-order', 'Before'))->setContact(['phone'=>'13800138000', 'email'=>'field@example.test']);
        $em->persist($store); $em->flush();

        $client->jsonRequest('POST', self::URL, ['entity'=>'Store','action'=>'update','identifier'=>$store->getUuid(),'changes'=>['contact'=>['phone'=>'13800138000']]]);
        self::assertResponseStatusCodeSame(400);

        $preview = $this->post($client, ['action'=>'update', 'identifier'=>$store->getUuid(), 'changes'=>[
            'address'=>['detail'=>'Room','city'=>'New City'], 'contact'=>['phone'=>'13900139000','email'=>'new@example.test'], 'name'=>'After',
        ]]);
        self::assertSame(['name','contact','address'], array_column($preview['differences'], 'property'));
        self::assertSame(['email'=>'new@example.test','phone'=>'13900139000'], $preview['differences'][1]['after']);
        self::assertSame(['city'=>'New City','detail'=>'Room'], $preview['differences'][2]['after']);
    }

    public function testReorderedStoredJsonDoesNotConflictOrFailReadback(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = (new Store('json-order', 'Before'))->setContact(['phone'=>'13800138000','email'=>'order@example.test'])->setAddress(['province'=>'P','city'=>'C']);
        $em->persist($store); $em->flush();
        $preview = $this->post($client, ['action'=>'update', 'identifier'=>$store->getUuid(), 'changes'=>['name'=>'After']]);
        $em->getConnection()->executeStatement('UPDATE store SET contact = ?, address = ? WHERE id = ?', [
            '{"email":"order@example.test","phone":"13800138000"}', '{"city":"C","province":"P"}', $store->getId(),
        ]);
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed'=>true]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string)$client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue(($body['data'] ?? $body)['verified']);
        $em->clear();
        $updated = $em->find(Store::class, $store->getId());
        self::assertSame('After', $updated->getName());
        self::assertSame(['email'=>'order@example.test','phone'=>'13800138000'], $updated->getContact());
    }

    private function post(KernelBrowser $client, array $payload): array
    {
        $client->jsonRequest('POST', self::URL, ['entity'=>'Store', ...$payload]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string)$client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return $body['data'] ?? $body;
    }
}
