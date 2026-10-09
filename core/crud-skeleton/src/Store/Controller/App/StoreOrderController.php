<?php

declare(strict_types=1);

namespace App\Store\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreOrderServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/store-orders', name: 'app-store-orders-')]
#[IsGranted('ROLE_USER')]
final class StoreOrderController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(protected readonly StoreOrderServiceInterface $service)
    {
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $user = $this->getUser();

        return $user instanceof User ? ['customerUserUuid' => $user->getUuid()] : ['id' => -1];
    }
}
