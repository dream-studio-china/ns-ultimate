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

final class MembershipAdminApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $token;
    private Store $store;
    private Membership $owner;
    private User $managerUser;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $admin = (new User())->setUsername('membership-api-admin')->setEmail('membership-api-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $ownerUser = (new User())->setUsername('membership-api-owner')->setEmail('membership-api-owner@example.test');
        $this->managerUser = (new User())->setUsername('membership-api-manager')->setEmail('membership-api-manager@example.test');
        $this->store = new Store('membership-api', 'Membership API store');
        $this->owner = new Membership($this->store, $ownerUser->getUuid(), Membership::ROLE_OWNER);
        foreach ([$admin, $ownerUser, $this->managerUser, $this->store, $this->owner] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
    }

    public function testAdminCanListDetailCreateAndUpdateMemberships(): void
    {
        $created = $this->request('POST', '/api/v1/manage/memberships', [
            'storeUuid' => $this->store->getUuid(),
            'userUuid' => $this->managerUser->getUuid(),
            'role' => Membership::ROLE_MANAGER,
        ]);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $createdData = $this->data($created);
        self::assertSame(Membership::ROLE_MANAGER, $createdData['role']);
        self::assertSame($this->store->getUuid(), $createdData['store']['uuid']);

        $detail = $this->request('GET', '/api/v1/manage/memberships/'.$createdData['uuid']);
        self::assertSame(200, $detail->getStatusCode(), $detail->getContent());
        self::assertSame($this->managerUser->getUuid(), $this->data($detail)['userUuid']);

        $updated = $this->request('PUT', '/api/v1/manage/memberships/'.$createdData['uuid'], ['role' => Membership::ROLE_CLERK]);
        self::assertSame(200, $updated->getStatusCode(), $updated->getContent());
        self::assertSame(Membership::ROLE_CLERK, $this->data($updated)['role']);

        $list = $this->request('GET', '/api/v1/manage/memberships');
        self::assertSame(200, $list->getStatusCode(), $list->getContent());
        $rows = json_decode($list->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertCount(2, $rows);
        self::assertContains($this->owner->getUuid(), array_column($rows, 'uuid'));
        $ownerRow = array_values(array_filter($rows, fn (array $row): bool => $row['uuid'] === $this->owner->getUuid()))[0];
        self::assertSame($this->store->getUuid(), $ownerRow['store']['uuid'] ?? null);
    }

    public function testCreateAcceptsRelationStyleStoreAndRejectsInvalidInput(): void
    {
        $created = $this->request('POST', '/api/v1/manage/memberships', [
            'store' => $this->store->getUuid(),
            'userUuid' => $this->managerUser->getUuid(),
            'role' => Membership::ROLE_CLERK,
        ]);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());

        foreach ([
            ['storeUuid' => $this->store->getUuid(), 'userUuid' => $this->managerUser->getUuid(), 'role' => 'unknown'],
            ['storeUuid' => $this->store->getUuid(), 'userUuid' => 'not-a-uuid', 'role' => Membership::ROLE_CLERK],
            ['storeUuid' => UUID::v4(), 'userUuid' => $this->managerUser->getUuid(), 'role' => Membership::ROLE_CLERK],
        ] as $body) {
            $response = $this->request('POST', '/api/v1/manage/memberships', $body);
            self::assertContains($response->getStatusCode(), [400, 404], $response->getContent());
        }
    }

    public function testLastActiveOwnerCannotLoseOwnerRole(): void
    {
        $response = $this->request('PUT', '/api/v1/manage/memberships/'.$this->owner->getUuid(), ['role' => Membership::ROLE_MANAGER]);
        self::assertSame(409, $response->getStatusCode(), $response->getContent());
        $this->em->clear();
        self::assertSame(Membership::ROLE_OWNER, $this->membership($this->owner->getUuid())->getRole());
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body = []): Response
    {
        return self::$kernel->handle(Request::create(
            $path,
            $method,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'],
            content: $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    private function membership(string $uuid): Membership
    {
        $membership = $this->em->getRepository(Membership::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Membership::class, $membership);
        return $membership;
    }
}
