<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Trade\DTO\StoreContext;

interface StoreContextResolverInterface
{
    public function resolve(): ?StoreContext;
}
