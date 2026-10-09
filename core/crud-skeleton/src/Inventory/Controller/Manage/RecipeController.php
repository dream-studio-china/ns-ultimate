<?php

declare(strict_types=1);

namespace App\Inventory\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Inventory\Service\SpecificationRecipeServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/inventory/recipes', name: 'manage-inventory-recipes-')]
#[IsGranted('ROLE_ADMIN')]
final class RecipeController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        UpdateApiViewMixin;

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['status'];

    public function __construct(protected readonly SpecificationRecipeServiceInterface $service)
    {
    }

    /** @return array<string, mixed> */
    protected function defaultCreateValues(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        return $content;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        return $entity;
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if (
            !is_array($data)
            || !is_string($data['specificationUuid'] ?? null)
            || !is_array($data['lines'] ?? null)
            || $data['lines'] === []
        ) {
            return $this->warning('specificationUuid and non-empty lines are required.', 400, '', 400);
        }
        try {
            /** @var list<array{materialUuid: string, quantityPerUnit: string, sort?: int}> $lines */
            $lines = [];
            foreach ($data['lines'] as $line) {
                if (
                    !is_array($line)
                    || !is_string($line['materialUuid'] ?? null)
                    || !is_string($line['quantityPerUnit'] ?? null)
                ) {
                    throw new \InvalidArgumentException('Each recipe line requires materialUuid and quantityPerUnit.');
                }
                if (isset($line['sort']) && !is_int($line['sort'])) {
                    throw new \InvalidArgumentException('Recipe line sort must be an integer.');
                }
                $recipeLine = [
                    'materialUuid' => $line['materialUuid'],
                    'quantityPerUnit' => $line['quantityPerUnit'],
                ];
                if (isset($line['sort'])) {
                    $recipeLine['sort'] = $line['sort'];
                }
                $lines[] = $recipeLine;
            }

            $recipe = $this->service->createRecipe($data['specificationUuid'], $lines);

            return $this->success($recipe, 'Success', 201);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }
}
