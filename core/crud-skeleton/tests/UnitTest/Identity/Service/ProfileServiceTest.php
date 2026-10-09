<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Service;

use App\Identity\Entity\Profile;
use App\Identity\Service\ProfileService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class ProfileServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private EntityRepository $repo;
    private ProfileService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(EntityRepository::class);

        $profileClass = Profile::class;
        $this->em->method('getRepository')->with($profileClass)->willReturn($this->repo);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')
            ->willReturnCallback(function (string $data, string $class, string $format, array $context) {
                $object = $context['object_to_populate'] ?? null;
                if ($object === null) {
                    return null;
                }
                $parsed = json_decode($data, true);
                if (is_array($parsed)) {
                    foreach ($parsed as $key => $value) {
                        $setter = 'set' . ucfirst($key);
                        if (method_exists($object, $setter)) {
                            $object->$setter($value);
                        }
                    }
                }
                return $object;
            });

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new \Symfony\Component\Validator\ConstraintViolationList());

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function (string $id) use ($serializer, $validator) {
                return match ($id) {
                    'doctrine.orm.entity_manager' => $this->em,
                    'logger' => $this->createMock(LoggerInterface::class),
                    'security.token_storage' => $this->createMock(TokenStorageInterface::class),
                    'validator' => $validator,
                    'serializer' => $serializer,
                    default => null,
                };
            });
        $container->method('has')->willReturn(true);

        $this->service = new ProfileService($container);
    }

    #[Group('low-value')]
    public function testNewReturnsProfileInstance(): void
    {
        $profile = $this->service->new();

        self::assertInstanceOf(Profile::class, $profile);
    }

    #[Group('low-value')]
    public function testGetCallsRepositoryFind(): void
    {
        $user = new \App\Identity\Entity\User();
        $profile = new Profile($user, Profile::LEVEL_GOLD);

        $this->repo->method('find')->with(42)->willReturn($profile);

        $result = $this->service->get(42);

        self::assertSame($profile, $result);
        self::assertSame(Profile::LEVEL_GOLD, $result->getLevel());
    }

    #[Group('low-value')]
    public function testGetReturnsNullForMissingId(): void
    {
        $this->repo->method('find')->with(999)->willReturn(null);

        $result = $this->service->get(999);

        self::assertNull($result);
    }

    #[Group('low-value')]
    public function testGetWithArrayCriteria(): void
    {
        $user = new \App\Identity\Entity\User();
        $profile = new Profile($user, Profile::LEVEL_SILVER);

        $this->repo->method('findOneBy')->with(['level' => 'silver'])->willReturn($profile);

        $result = $this->service->get(['level' => 'silver']);

        self::assertSame($profile, $result);
    }

    #[Group('low-value')]
    public function testUpdatePersistsAndFlushes(): void
    {
        $user = new \App\Identity\Entity\User();
        $profile = new Profile($user);

        $this->em->expects(self::once())->method('persist')->with($profile);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($profile, ['level' => Profile::LEVEL_PLATINUM]);

        self::assertSame(Profile::LEVEL_PLATINUM, $result->getLevel());
    }

    #[Group('low-value')]
    public function testRemovePersistsAndFlushes(): void
    {
        $user = new \App\Identity\Entity\User();
        $profile = new Profile($user);

        $this->repo->method('find')->with(1)->willReturn($profile);

        $this->em->expects(self::once())->method('remove')->with($profile);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->remove(1);

        self::assertTrue($result);
    }

    // ──────────────────────── joinAsMember ────────────────────────

    public function testJoinAsMemberCreatesNewProfile(): void
    {
        $user = new \App\Identity\Entity\User();

        $this->repo->method('findOneBy')->with(['user' => $user])->willReturn(null);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $profile = $this->service->joinAsMember($user);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame($user, $profile->getUser());
        self::assertSame(Profile::LEVEL_BRONZE, $profile->getLevel());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getCreatedAt());
    }

    public function testJoinAsMemberReturnsExistingProfile(): void
    {
        $user = new \App\Identity\Entity\User();
        $existingProfile = new Profile($user, Profile::LEVEL_GOLD);

        $this->repo->method('findOneBy')->with(['user' => $user])->willReturn($existingProfile);

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $profile = $this->service->joinAsMember($user);

        self::assertSame($existingProfile, $profile);
        self::assertSame(Profile::LEVEL_GOLD, $profile->getLevel());
    }

    public function testJoinAsMemberDefaultLevel(): void
    {
        $user = new \App\Identity\Entity\User();

        $this->repo->method('findOneBy')->with(['user' => $user])->willReturn(null);

        $profile = $this->service->joinAsMember($user);

        self::assertSame(Profile::LEVEL_BRONZE, $profile->getLevel());
    }

    #[Group('low-value')]
    public function testUpdateProfileFields(): void
    {
        $user = new \App\Identity\Entity\User();
        $profile = new Profile($user);

        $this->em->expects(self::once())->method('persist')->with($profile);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($profile, [
            'nickname' => 'Johnny',
            'avatar' => 'https://example.com/photo.jpg',
            'metadata' => ['pref_lang' => 'zh'],
        ]);

        self::assertSame('Johnny', $result->getNickname());
        self::assertSame('https://example.com/photo.jpg', $result->getAvatar());
        self::assertSame(['pref_lang' => 'zh'], $result->getMetadata());
    }

    #[Group('low-value')]
    public function testUpdateClearsProfileFields(): void
    {
        $user = new \App\Identity\Entity\User();
        $profile = new Profile($user);
        $profile->setNickname('OldName');
        $profile->setAvatar('https://old.com/av.jpg');

        $result = $this->service->update($profile, ['nickname' => null, 'avatar' => null]);

        self::assertNull($result->getNickname());
        self::assertNull($result->getAvatar());
    }
}
