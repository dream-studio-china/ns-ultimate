<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Contract;

/** The embedding application supplies a stable, authenticated, non-reusable account key. */
interface ActorIdentityProvider
{
    public function actorKey(): string;
}
