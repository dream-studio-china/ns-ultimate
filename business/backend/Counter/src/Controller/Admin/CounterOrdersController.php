<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\Admin;

use NsUltimate\Business\Counter\Service\AdminCounterOrders;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/counter-orders', name: 'manage-counter-orders-')]
#[IsGranted('ROLE_ADMIN')]
final class CounterOrdersController
{
    public function __construct(private readonly AdminCounterOrders $orders) {}

    #[Route('/stores', name: 'stores', methods: ['GET'])]
    public function stores(Request $request): JsonResponse
    {
        return new JsonResponse($this->orders->stores($request->query));
    }

    #[Route('', name: 'counters', methods: ['GET'])]
    public function counters(Request $request): JsonResponse
    {
        return new JsonResponse($this->orders->counters($request->query));
    }

    #[Route('/{counter}/orders', name: 'history', methods: ['GET'])]
    public function history(string $counter, Request $request): JsonResponse
    {
        return new JsonResponse($this->orders->history($counter, $request->query));
    }

    #[Route('/{counter}/orders/{order}/graph', name: 'graph', methods: ['GET'])]
    public function graph(string $counter, string $order): JsonResponse
    {
        return new JsonResponse($this->orders->graph($counter, $order));
    }
}
