<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Core\Service\BaseServiceInterface;
use App\Inventory\Entity\SpecificationRecipe;

/** @extends BaseServiceInterface<SpecificationRecipe> */
interface SpecificationRecipeServiceInterface extends BaseServiceInterface
{
    /**
     * @param list<array{materialUuid: string, quantityPerUnit: string, sort?: int}> $lines
     */
    public function createRecipe(string $specificationUuid, array $lines): SpecificationRecipe;
}
