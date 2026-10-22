<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend;

use App\Identity\Entity\User;
use NsUltimate\Apps\AdminAgent\Backend\Contract\ActorIdentityProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AdminAgentActorIdentity implements ActorIdentityProvider
{
    public function __construct(private readonly Security $security) {}

    public function actorKey(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) throw new AccessDeniedException('An authenticated account is required.');
        return $user->getUuid();
    }
}
