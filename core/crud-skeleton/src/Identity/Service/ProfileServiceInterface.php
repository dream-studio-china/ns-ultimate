<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\Core\Service\BaseServiceInterface;
use App\Identity\Entity\Profile;
use App\Identity\Entity\User;

/** @extends BaseServiceInterface<\App\Identity\Entity\Profile> */
interface ProfileServiceInterface extends BaseServiceInterface
{
    /**
     * Create a Profile record for the given user at the default (lowest) level.
     * Idempotent: if the user already has a profile, returns the existing record.
     */
    public function joinAsMember(User $user): Profile;
}
