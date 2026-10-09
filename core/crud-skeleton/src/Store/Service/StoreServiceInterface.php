<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseServiceInterface;
use App\Store\Entity\Store;

/** @extends BaseServiceInterface<Store> */
interface StoreServiceInterface extends BaseServiceInterface
{
    public function createStore(string $code, string $name, string $timezone): Store;
    public function findActiveByUuid(string $uuid): ?Store;
}
