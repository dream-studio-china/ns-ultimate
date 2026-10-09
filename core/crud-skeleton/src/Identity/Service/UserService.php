<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\Core\Service\BaseService;
use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** @extends BaseService<\App\Identity\Entity\User> */
final class UserService extends BaseService implements UserServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct($container, User::class);
    }

    public function update(mixed $object, ?array $data = null, bool $noFlush = false): object|false
    {
        if ($object instanceof User && is_array($data) && isset($data['password']) && is_string($data['password']) && $data['password'] !== '') {
            $data['password'] = $this->passwordHasher->hashPassword($object, $data['password']);
        } elseif (is_array($data) && isset($data['password']) && $data['password'] === '') {
            unset($data['password']);
        }

        return parent::update($object, $data, $noFlush);
    }

    public function register(string $email, string $username, string $password, ?string $phone = null): User
    {
        $email = mb_strtolower(trim($email));
        $username = mb_strtolower(trim($username));

        if ($email === '' || $username === '' || $password === '') {
            throw new \InvalidArgumentException('Email, username, and password are required.');
        }

        if (mb_strlen($password) < 6) {
            throw new \InvalidArgumentException('Password must be at least 6 characters.');
        }

        if ($this->userRepository->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Email already exists.');
        }

        if ($this->userRepository->findByUsername($username) !== null) {
            throw new \InvalidArgumentException('Username already exists.');
        }

        if ($phone !== null && $phone !== '' && $this->userRepository->findByPhone($phone) !== null) {
            throw new \InvalidArgumentException('Phone already exists.');
        }

        $user = (new User())
            ->setEmail($email)
            ->setUsername($username)
            ->setPhone($phone !== '' ? $phone : null);

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function verifyPassword(User $user, string $password): bool
    {
        return $this->passwordHasher->isPasswordValid($user, $password);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if ($currentPassword === '' || $newPassword === '') {
            throw new \InvalidArgumentException('currentPassword and newPassword are required.');
        }

        if (mb_strlen($newPassword) < 6) {
            throw new \InvalidArgumentException('New password must be at least 6 characters.');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new \InvalidArgumentException('Current password is incorrect.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();
    }

    public function adminChangePassword(User $user, string $newPassword): void
    {
        if ($newPassword === '') {
            throw new \InvalidArgumentException('newPassword is required.');
        }

        if (mb_strlen($newPassword) < 6) {
            throw new \InvalidArgumentException('New password must be at least 6 characters.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProfile(User $user, array $data): User
    {
        if (isset($data['email'])) {
            $email = mb_strtolower(trim((string) $data['email']));
            if ($email !== $user->getEmail() && $this->userRepository->findByEmail($email) !== null) {
                throw new \InvalidArgumentException('Email already exists.');
            }
            $user->setEmail($email);
        }

        if (isset($data['username'])) {
            $username = mb_strtolower(trim((string) $data['username']));
            if ($username !== $user->getUsername() && $this->userRepository->findByUsername($username) !== null) {
                throw new \InvalidArgumentException('Username already exists.');
            }
            $user->setUsername($username);
        }

        if (array_key_exists('phone', $data)) {
            $phone = $data['phone'] !== null ? trim((string) $data['phone']) : null;
            if ($phone !== null && $phone !== $user->getPhone() && $this->userRepository->findByPhone($phone) !== null) {
                throw new \InvalidArgumentException('Phone already exists.');
            }
            $user->setPhone($phone);
        }

        if (isset($data['password']) && is_string($data['password']) && $data['password'] !== '') {
            if (mb_strlen($data['password']) < 6) {
                throw new \InvalidArgumentException('Password must be at least 6 characters.');
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
        }

        $this->em->flush();

        return $user;
    }
}
