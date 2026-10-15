<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Trade\Service\StoreContextResolverInterface;
use NsUltimate\Business\Counter\Service\CounterService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/counters', name: 'app-counters-')]
#[IsGranted('ROLE_USER')]
final class CounterController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly CounterService $service,
        private readonly StoreContextResolverInterface $storeContextResolver,
    ) {
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $storeContext = $this->storeContextResolver->resolve();
        if ($storeContext === null) {
            return ['id' => -1];
        }

        return ['storeUuid' => $storeContext->storeUuid, 'enabled' => true];
    }
}
