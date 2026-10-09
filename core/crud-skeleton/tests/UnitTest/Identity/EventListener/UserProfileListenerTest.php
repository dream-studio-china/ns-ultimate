<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\EventListener;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Identity\EventListener\UserProfileListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UserProfileListenerTest extends TestCase
{
    public function testPostPersistCreatesProfileForNewUser(): void
    {
        $user = new User();
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $em->method('getRepository')->with(Profile::class)->willReturn($repo);
        $repo->method('findOneBy')->with(['user' => $user])->willReturn(null);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Profile::class));
        $em->expects(self::once())->method('flush');

        $listener = new UserProfileListener();
        $args = new PostPersistEventArgs($user, $em);
        $listener->postPersist($args);
    }

    public function testPostPersistSkipsWhenProfileExists(): void
    {
        $user = new User();
        $existingProfile = new Profile($user);
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $em->method('getRepository')->with(Profile::class)->willReturn($repo);
        $repo->method('findOneBy')->with(['user' => $user])->willReturn($existingProfile);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $listener = new UserProfileListener();
        $args = new PostPersistEventArgs($user, $em);
        $listener->postPersist($args);
    }

    public function testPostPersistCreatesProfileAtDefaultLevel(): void
    {
        $user = new User();
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);
        $persistedProfile = null;

        $em->method('getRepository')->with(Profile::class)->willReturn($repo);
        $repo->method('findOneBy')->with(['user' => $user])->willReturn(null);
        $em->method('persist')->willReturnCallback(function (Profile $profile) use (&$persistedProfile) {
            $persistedProfile = $profile;
        });

        $listener = new UserProfileListener();
        $args = new PostPersistEventArgs($user, $em);
        $listener->postPersist($args);

        self::assertNotNull($persistedProfile);
        self::assertSame(Profile::LEVEL_BRONZE, $persistedProfile->getLevel());
        self::assertSame($user, $persistedProfile->getUser());
    }

    public function testPostPersistIgnoresNonUserEntities(): void
    {
        $nonUser = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $em->expects(self::never())->method('persist');

        $listener = new UserProfileListener();
        $args = new PostPersistEventArgs($nonUser, $em);
        $listener->postPersist($args);
    }
}
