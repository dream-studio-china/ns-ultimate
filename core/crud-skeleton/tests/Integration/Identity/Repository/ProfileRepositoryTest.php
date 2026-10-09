<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Repository;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Identity\Repository\ProfileRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileRepositoryTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Identity\\Entity\\Profile p')->execute();
        $em->createQuery('DELETE FROM App\\Identity\\Entity\\User u')->execute();

        self::ensureKernelShutdown();
    }

    public function testFindByIdReturnsProfile(): void
    {
        $profile = $this->createProfile('repo1@test.com', 'repo1', Profile::LEVEL_SILVER);

        $client = static::createClient();
        /** @var ProfileRepository $repo */
        $repo = $client->getContainer()->get(ProfileRepository::class);

        $found = $repo->findById($profile->getId());

        self::assertNotNull($found);
        self::assertSame($profile->getId(), $found->getId());
        self::assertSame('silver', $found->getLevel());
    }

    public function testFindByIdReturnsNullForMissingId(): void
    {
        $client = static::createClient();
        /** @var ProfileRepository $repo */
        $repo = $client->getContainer()->get(ProfileRepository::class);

        $found = $repo->findById(99999);

        self::assertNull($found);
    }

    public function testFindByUserReturnsProfile(): void
    {
        $profile = $this->createProfile('repo2@test.com', 'repo2', Profile::LEVEL_GOLD);

        $client = static::createClient();
        /** @var ProfileRepository $repo */
        $repo = $client->getContainer()->get(ProfileRepository::class);

        $found = $repo->findByUser($profile->getUser());

        self::assertNotNull($found);
        self::assertSame(Profile::LEVEL_GOLD, $found->getLevel());
    }

    public function testFindByUserIdReturnsProfile(): void
    {
        $profile = $this->createProfile('repo4@test.com', 'repo4', Profile::LEVEL_BRONZE);

        $client = static::createClient();
        /** @var ProfileRepository $repo */
        $repo = $client->getContainer()->get(ProfileRepository::class);

        $found = $repo->findByUserId($profile->getUser()->getId());

        self::assertNotNull($found);
        self::assertSame('bronze', $found->getLevel());
    }

    public function testFindByLevelReturnsProfiles(): void
    {
        $this->createProfile('repo5@test.com', 'repo5', Profile::LEVEL_GOLD);
        $this->createProfile('repo6@test.com', 'repo6', Profile::LEVEL_GOLD);
        $this->createProfile('repo7@test.com', 'repo7', Profile::LEVEL_SILVER);

        $client = static::createClient();
        /** @var ProfileRepository $repo */
        $repo = $client->getContainer()->get(ProfileRepository::class);

        $goldProfiles = $repo->findByLevel(Profile::LEVEL_GOLD);
        $silverProfiles = $repo->findByLevel(Profile::LEVEL_SILVER);
        $diamondProfiles = $repo->findByLevel(Profile::LEVEL_DIAMOND);

        self::assertCount(2, $goldProfiles);
        self::assertCount(1, $silverProfiles);
        self::assertCount(0, $diamondProfiles);
    }

    public function testFindByLevelOrAboveReturnsCumulative(): void
    {
        $this->createProfile('repo8@test.com', 'repo8', Profile::LEVEL_BRONZE);
        $this->createProfile('repo9@test.com', 'repo9', Profile::LEVEL_SILVER);
        $this->createProfile('repo10@test.com', 'repo10', Profile::LEVEL_GOLD);
        $this->createProfile('repo11@test.com', 'repo11', Profile::LEVEL_PLATINUM);
        $this->createProfile('repo12@test.com', 'repo12', Profile::LEVEL_DIAMOND);

        $client = static::createClient();
        /** @var ProfileRepository $repo */
        $repo = $client->getContainer()->get(ProfileRepository::class);

        self::assertCount(5, $repo->findByLevelOrAbove(Profile::LEVEL_BRONZE));
        self::assertCount(4, $repo->findByLevelOrAbove(Profile::LEVEL_SILVER));
        self::assertCount(3, $repo->findByLevelOrAbove(Profile::LEVEL_GOLD));
        self::assertCount(2, $repo->findByLevelOrAbove(Profile::LEVEL_PLATINUM));
        self::assertCount(1, $repo->findByLevelOrAbove(Profile::LEVEL_DIAMOND));
    }

    #[Group('low-value')]
    public function testProfileStoresNickname(): void
    {
        $profile = $this->createProfile('repo14@test.com', 'repo14', Profile::LEVEL_GOLD, 'Johnny');

        $client = static::createClient();
        /** @var ProfileRepository $repo */
        $repo = $client->getContainer()->get(ProfileRepository::class);

        $found = $repo->findById($profile->getId());
        self::assertNotNull($found);
        self::assertSame('Johnny', $found->getNickname());
    }

    // ───────────────────── helpers ─────────────────────

    private function createProfile(string $email, string $username, string $level, ?string $nickname = null): Profile
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($hasher->hashPassword($user, 'Pass123!'));

        $profile = new Profile($user, $level);
        if ($nickname !== null) {
            $profile->setNickname($nickname);
        }

        $em->persist($user);
        $em->persist($profile);
        $em->flush();

        self::ensureKernelShutdown();

        return $profile;
    }
}
