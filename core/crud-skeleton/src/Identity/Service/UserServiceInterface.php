<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\Core\Service\BaseServiceInterface;
use App\Identity\Entity\User;

/** @extends BaseServiceInterface<\App\Identity\Entity\User> */
interface UserServiceInterface extends BaseServiceInterface
{
    public function update(mixed $object, ?array $data = null, bool $noFlush = false): object|false;

    public function register(string $email, string $username, string $password, ?string $phone = null): User;

    public function verifyPassword(User $user, string $password): bool;

    public function changePassword(User $user, string $currentPassword, string $newPassword): void;

    public function adminChangePassword(User $user, string $newPassword): void;

    /**
     * @param array<string, mixed> $data
     */
    public function updateProfile(User $user, array $data): User;
}
