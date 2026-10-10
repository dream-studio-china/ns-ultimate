<?php

declare(strict_types=1);

namespace App\Store\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\ListApiViewMixin;
use App\Store\Service\ProductCategoryServiceInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/product-categories', name: 'app-product-categories-')]
final class ProductCategoryController extends RestController
{
    use ApiView, ListApiViewMixin;

    public function __construct(protected readonly ProductCategoryServiceInterface $service) {}

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        return ['store' => null, 'enabled' => true];
    }
}
