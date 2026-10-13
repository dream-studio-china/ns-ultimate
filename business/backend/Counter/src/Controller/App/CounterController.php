<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use NsUltimate\Business\Counter\Service\CounterService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/stores/{storeUuid}/counters', name: 'app-store-counters-', requirements: ['storeUuid' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class CounterController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly CounterService $service,
        private readonly StoreServiceInterface $storeService,
    ) {
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $storeUuid = $this->getRequestStack()->getCurrentRequest()?->attributes->get('storeUuid');
        if (!is_string($storeUuid) || $storeUuid === '') {
            throw new NotFoundHttpException('Store not found.');
        }
        $store = $this->storeService->get(['uuid' => $storeUuid], false);
        if (!$store instanceof Store || !$store->isActive()) {
            throw new NotFoundHttpException('Store not found.');
        }

        return ['storeUuid' => $store->getUuid(), 'enabled' => true];
    }
}
