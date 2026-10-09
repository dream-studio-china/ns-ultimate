<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Service;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class UserServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;
    private UserRepository $userRepo;
    private UserService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->userRepo = $this->createMock(UserRepository::class);

        $userClass = User::class;
        $this->em->method('getRepository')->with($userClass)->willReturn($this->userRepo);

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

        $this->service = new UserService($container, $this->hasher, $this->userRepo);
    }

    // ──────────────────────────── register ────────────────────────────

    public function testRegisterCreatesUser(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(null);
        $this->userRepo->method('findByUsername')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed_pw_example');

        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        $this->em->expects(self::once())->method('flush');

        $user = $this->service->register('user@test.com', 'testuser', 'P@ssw0rd');

        self::assertSame('user@test.com', $user->getEmail());
        self::assertSame('testuser', $user->getUsername());
        self::assertSame('hashed_pw_example', $user->getPassword());
        self::assertNull($user->getPhone());
    }

    public function testRegisterWithPhone(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(null);
        $this->userRepo->method('findByUsername')->willReturn(null);
        $this->userRepo->method('findByPhone')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $user = $this->service->register('p@t.com', 'phoneuser', 'Pass123', '+8613912345678');

        self::assertSame('+8613912345678', $user->getPhone());
    }

    public function testRegisterEmptyFieldsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email, username, and password are required.');
        $this->service->register('', '', '');
    }

    public function testRegisterShortPasswordThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 6 characters.');
        $this->service->register('a@b.com', 'user', '12345');
    }

    public function testRegisterDuplicateEmailThrows(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(new User());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email already exists.');
        $this->service->register('dup@test.com', 'user', 'P@ssw0rd');
    }

    public function testRegisterDuplicateUsernameThrows(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(null);
        $this->userRepo->method('findByUsername')->willReturn(new User());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already exists.');
        $this->service->register('ok@test.com', 'dupuser', 'P@ssw0rd');
    }

    public function testRegisterDuplicatePhoneThrows(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(null);
        $this->userRepo->method('findByUsername')->willReturn(null);
        $this->userRepo->method('findByPhone')->willReturn(new User());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone already exists.');
        $this->service->register('ok@test.com', 'okuser', 'P@ssw0rd', '+8613900000000');
    }

    // ──────────────────────── verifyPassword ────────────────────────

    public function testVerifyPasswordValid(): void
    {
        $user = new User();
        $this->hasher->method('isPasswordValid')->with($user, 'correct')->willReturn(true);

        self::assertTrue($this->service->verifyPassword($user, 'correct'));
    }

    public function testVerifyPasswordInvalid(): void
    {
        $user = new User();
        $this->hasher->method('isPasswordValid')->with($user, 'wrong')->willReturn(false);

        self::assertFalse($this->service->verifyPassword($user, 'wrong'));
    }

    // ──────────────────────── changePassword ────────────────────────

    public function testChangePassword(): void
    {
        $user = new User();
        $user->setPassword('old_hash');
        $this->hasher->method('isPasswordValid')->with($user, 'oldpass')->willReturn(true);
        $this->hasher->method('hashPassword')->with($user, 'newpass')->willReturn('new_hash');

        $this->em->expects(self::once())->method('flush');

        $this->service->changePassword($user, 'oldpass', 'newpass');

        self::assertSame('new_hash', $user->getPassword());
    }

    public function testChangePasswordWrongCurrentThrows(): void
    {
        $user = new User();
        $this->hasher->method('isPasswordValid')->with($user, 'wrong')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Current password is incorrect.');
        $this->service->changePassword($user, 'wrong', 'newpass1');
    }

    public function testChangePasswordEmptyFieldsThrows(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('currentPassword and newPassword are required.');
        $this->service->changePassword($user, '', '');
    }

    public function testChangePasswordShortNewThrows(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('New password must be at least 6 characters.');
        $this->service->changePassword($user, 'old', '12345');
    }

    // ──────────────────────── adminChangePassword ────────────────────────

    public function testAdminChangePassword(): void
    {
        $user = new User();
        $this->hasher->method('hashPassword')->with($user, 'AdminSet1')->willReturn('admin_hash');

        $this->em->expects(self::once())->method('flush');

        $this->service->adminChangePassword($user, 'AdminSet1');

        self::assertSame('admin_hash', $user->getPassword());
    }

    public function testAdminChangePasswordEmptyThrows(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('newPassword is required.');
        $this->service->adminChangePassword($user, '');
    }

    public function testAdminChangePasswordShortThrows(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('New password must be at least 6 characters.');
        $this->service->adminChangePassword($user, 'ab');
    }

    // ──────────────────────── updateProfile ────────────────────────

    public function testUpdateProfileEmail(): void
    {
        $user = new User();
        $user->setEmail('old@test.com');

        $this->userRepo->method('findByEmail')->willReturn(null);

        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateProfile($user, ['email' => 'new@test.com']);

        self::assertSame('new@test.com', $result->getEmail());
    }

    public function testUpdateProfileUsername(): void
    {
        $user = new User();
        $user->setUsername('oldname');

        $this->userRepo->method('findByUsername')->willReturn(null);

        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateProfile($user, ['username' => 'newname']);

        self::assertSame('newname', $result->getUsername());
    }

    public function testUpdateProfilePhone(): void
    {
        $user = new User();

        $this->userRepo->method('findByPhone')->willReturn(null);

        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateProfile($user, ['phone' => '+8613900000000']);

        self::assertSame('+8613900000000', $result->getPhone());
    }

    public function testUpdateProfileClearPhone(): void
    {
        $user = new User();
        $user->setPhone('+8613900000000');

        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateProfile($user, ['phone' => null]);

        self::assertNull($result->getPhone());
    }

    public function testUpdateProfilePassword(): void
    {
        $user = new User();
        $this->hasher->method('hashPassword')->with($user, 'NewProf1')->willReturn('profile_hash');

        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateProfile($user, ['password' => 'NewProf1']);

        self::assertSame('profile_hash', $result->getPassword());
    }

    public function testUpdateProfileDuplicateEmailThrows(): void
    {
        $user = new User();
        $user->setEmail('old@test.com');
        $this->userRepo->method('findByEmail')->willReturn(new User());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email already exists.');
        $this->service->updateProfile($user, ['email' => 'taken@test.com']);
    }

    public function testUpdateProfileDuplicateUsernameThrows(): void
    {
        $user = new User();
        $user->setUsername('oldname');
        $this->userRepo->method('findByUsername')->willReturn(new User());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already exists.');
        $this->service->updateProfile($user, ['username' => 'taken']);
    }

    public function testUpdateProfileDuplicatePhoneThrows(): void
    {
        $user = new User();
        $this->userRepo->method('findByPhone')->willReturn(new User());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone already exists.');
        $this->service->updateProfile($user, ['phone' => '+8613988888888']);
    }

    public function testUpdateProfileShortPasswordThrows(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 6 characters.');
        $this->service->updateProfile($user, ['password' => 'ab']);
    }

    public function testUpdateProfileSameEmailNoCheck(): void
    {
        $user = new User();
        $user->setEmail('same@test.com');

        // Email unchanged — no duplicate check needed
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateProfile($user, ['email' => 'same@test.com']);

        self::assertSame('same@test.com', $result->getEmail());
    }

    // ──────────────────────── update (BaseService override) ────────────────────────

    public function testUpdateHashesPassword(): void
    {
        $user = new User();
        $this->hasher->method('hashPassword')->willReturn('hashed_update');

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $this->service->update($user, ['password' => 'NewPass123', 'username' => 'updated']);

        self::assertSame('hashed_update', $user->getPassword());
        self::assertSame('updated', $user->getUsername());
    }

    public function testUpdateSkipsEmptyPassword(): void
    {
        $user = new User();
        $user->setPassword('existing_hash');

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $this->service->update($user, ['password' => '', 'username' => 'updated']);

        // Password should NOT be re-hashed
        self::assertSame('existing_hash', $user->getPassword());
        self::assertSame('updated', $user->getUsername());
    }
}
