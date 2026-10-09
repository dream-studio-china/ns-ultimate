<?php

declare(strict_types=1);

namespace App\Authorization\Service;

interface ScopedResourceInterface
{
    public function getAuthorizationScope(): ?AuthorizationScope;
}
