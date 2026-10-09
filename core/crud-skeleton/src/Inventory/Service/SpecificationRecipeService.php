<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Core\Service\BaseService;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\SpecificationRecipe;
use App\Inventory\Repository\MaterialRepository;
use App\Inventory\Repository\SpecificationRecipeRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<SpecificationRecipe> */
final class SpecificationRecipeService extends BaseService implements SpecificationRecipeServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly SpecificationRecipeRepository $recipes,
        private readonly MaterialRepository $materials,
    ) {
        parent::__construct($container, SpecificationRecipe::class);
    }

    public function createRecipe(string $specificationUuid, array $lines): SpecificationRecipe
    {
        if ($this->recipes->findOneBy(['specificationUuid' => $specificationUuid]) !== null) {
            throw new \LogicException('A recipe already exists for this specification.');
        }

        $recipe = new SpecificationRecipe($specificationUuid);
        foreach ($lines as $index => $line) {
            $material = $this->materials->findOneByUuid($line['materialUuid']);
            if ($material === null || !$material->isActive()) {
                throw new \InvalidArgumentException('Recipe material was not found or is inactive.');
            }

            $recipe->addLine(new RecipeLine(
                $material,
                $line['quantityPerUnit'],
                $line['sort'] ?? $index,
            ));
        }

        $this->update($recipe, []);

        return $recipe;
    }
}
