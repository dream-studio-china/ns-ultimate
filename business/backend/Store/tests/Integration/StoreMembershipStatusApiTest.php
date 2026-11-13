<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StoreMembershipStatusApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $token;
    private Store $store;
    private Store $otherStore;
    private Membership $owner;
    private Membership $manager;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $admin = (new User())->setUsername('membership-admin')->setEmail('membership-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $ownerUser = (new User())->setUsername('membership-owner')->setEmail('membership-owner@example.test');
        $managerUser = (new User())->setUsername('membership-manager')->setEmail('membership-manager@example.test');
        $otherUser = (new User())->setUsername('membership-other')->setEmail('membership-other@example.test');
        $this->store = new Store('membership-store', 'Membership store');
        $this->otherStore = new Store('membership-other', 'Other store');
        $this->owner = new Membership($this->store, $ownerUser->getUuid(), Membership::ROLE_OWNER);
        $this->manager = new Membership($this->store, $managerUser->getUuid(), Membership::ROLE_MANAGER);
        $other = new Membership($this->otherStore, $otherUser->getUuid(), Membership::ROLE_MANAGER);
        foreach ([$admin, $ownerUser, $managerUser, $otherUser, $this->store, $this->otherStore, $this->owner, $this->manager, $other] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
    }

    public function testAdminCanSuspendRevokeAndActivateMembership(): void
    {
        $suspended = $this->request('suspend', $this->manager->getUuid());
        self::assertSame(200, $suspended->getStatusCode(), $suspended->getContent());
        self::assertSame(Membership::STATUS_SUSPENDED, $this->membership($this->manager->getUuid())->getStatus());
        self::assertSame(200, $this->request('suspend', $this->manager->getUuid())->getStatusCode());

        self::assertSame(200, $this->request('activate', $this->manager->getUuid())->getStatusCode());
        self::assertSame(Membership::STATUS_ACTIVE, $this->membership($this->manager->getUuid())->getStatus());

        self::assertSame(200, $this->request('revoke', $this->manager->getUuid())->getStatusCode());
        self::assertSame(Membership::STATUS_REVOKED, $this->membership($this->manager->getUuid())->getStatus());
    }

    public function testLastActiveOwnerCannotBeSuspendedOrRevoked(): void
    {
        self::assertSame(409, $this->request('suspend', $this->owner->getUuid())->getStatusCode());
        self::assertSame(409, $this->request('revoke', $this->owner->getUuid())->getStatusCode());
        self::assertSame(Membership::STATUS_ACTIVE, $this->membership($this->owner->getUuid())->getStatus());

        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $this->store->getUuid()]);
        self::assertInstanceOf(Store::class, $store);
        $secondOwner = new Membership($store, UUID::v4(), Membership::ROLE_OWNER);
        $this->em->persist($secondOwner);
        $this->em->flush();
        self::assertSame(200, $this->request('suspend', $this->owner->getUuid())->getStatusCode());
    }

    public function testUnknownOrForeignMembershipsReturnNotFound(): void
    {
        self::assertSame(404, $this->request('suspend', UUID::v4())->getStatusCode());
        self::assertSame(404, $this->requestWithStore($this->otherStore->getUuid(), 'suspend', $this->manager->getUuid())->getStatusCode());
    }

    public function testNonAdminCannotChangeMembershipStatus(): void
    {
        $user = (new User())->setUsername('membership-user')->setEmail('membership-user@example.test')->setRoles(['ROLE_USER']);
        $this->em->persist($user);
        $this->em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        self::assertSame(403, $this->request('suspend', $this->manager->getUuid(), $token)->getStatusCode());
    }

    private function request(string $action, string $membershipUuid, ?string $token = null): Response
    {
        return $this->requestWithStore($this->store->getUuid(), $action, $membershipUuid, $token);
    }

    private function requestWithStore(string $storeUuid, string $action, string $membershipUuid, ?string $token = null): Response
    {
        return self::$kernel->handle(Request::create(
            '/api/v1/manage/stores/'.$storeUuid.'/members/'.$membershipUuid.'/'.$action,
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token)],
        ));
    }

    private function membership(string $uuid): Membership
    {
        $membership = $this->em->getRepository(Membership::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Membership::class, $membership);
        return $membership;
    }
}
