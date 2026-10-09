<?php

declare(strict_types=1);

namespace App\Store\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Service\StoreServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/stores', name: 'app-stores-')]
#[IsGranted('ROLE_USER')]
final class StoreController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(protected readonly StoreServiceInterface $service)
    {
    }

    /** @return array<string, string> */
    protected function commonFilter(): array
    {
        return ['status' => 'active'];
    }
}
