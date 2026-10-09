<?php

namespace App\Common\Repository;

use App\Common\Entity\Content;

interface ContentRepositoryInterface
{
    /** @return Content[] */
    public function findLatest(int $limit = 10): array;

    public function findById(int $id): ?Content;
}
