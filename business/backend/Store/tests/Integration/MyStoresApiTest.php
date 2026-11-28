<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

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

final class MyStoresApiTest extends KernelTestCase
{
    private const PATH = '/api/v1/app/me/stores';

    protected static function getKernelClass(): string { return Kernel::class; }

    public function testListsOnlyTheAuthenticatedUsersActiveMembershipsAtActiveStores(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $user = (new User())->setUsername('store-reader')->setEmail('store-reader@example.test');
        $other = (new User())->setUsername('other-member')->setEmail('other-member@example.test');
        $admin = (new User())->setUsername('store-admin')->setEmail('store-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $alpha = new Store('alpha', 'Alpha');
        $zulu = new Store('zulu', 'Zulu');
        $suspendedMembership = new Store('suspended-member', 'Suspended membership');
        $revokedMembership = new Store('revoked-member', 'Revoked membership');
        $closed = (new Store('closed', 'Closed store'))->close();
        $suspended = (new Store('suspended', 'Suspended store'))->suspend();
        $foreign = new Store('foreign', 'Other member store');
        foreach ([$user, $other, $admin, $alpha, $zulu, $suspendedMembership, $revokedMembership, $closed, $suspended, $foreign,
            new Membership($zulu, $user->getUuid(), Membership::ROLE_CLERK),
            new Membership($alpha, $user->getUuid(), Membership::ROLE_OWNER),
            (new Membership($suspendedMembership, $user->getUuid(), Membership::ROLE_MANAGER))->suspend(),
            (new Membership($revokedMembership, $user->getUuid(), Membership::ROLE_MANAGER))->revoke(),
            new Membership($closed, $user->getUuid(), Membership::ROLE_OWNER),
            new Membership($suspended, $user->getUuid(), Membership::ROLE_OWNER),
            new Membership($foreign, $other->getUuid(), Membership::ROLE_MANAGER),
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        self::assertSame(401, self::$kernel->handle(Request::create(self::PATH))->getStatusCode());
        $response = $this->get($user, ['userUuid' => $other->getUuid()]);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame([
            ['uuid' => $alpha->getUuid(), 'name' => 'Alpha', 'code' => 'alpha', 'role' => Membership::ROLE_OWNER],
            ['uuid' => $zulu->getUuid(), 'name' => 'Zulu', 'code' => 'zulu', 'role' => Membership::ROLE_CLERK],
        ], $this->data($response));
        self::assertSame([], $this->data($this->get($admin)));
    }

    /** @param array<string, string> $query */
    private function get(User $user, array $query = []): Response
    {
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);

        return self::$kernel->handle(Request::create(self::PATH, 'GET', $query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]));
    }

    /** @return list<array<string, string>> */
    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
