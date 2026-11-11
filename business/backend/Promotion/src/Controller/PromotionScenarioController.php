<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Controller;

use NsUltimate\Business\Promotion\Service\PromotionScenarioSimulator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PromotionScenarioController extends AbstractController
{
    #[Route('/api/v1/manage/promotion-simulation/current', name: 'business-promotion-simulation-current', methods: ['GET'])]
    public function current(Request $request, PromotionScenarioSimulator $simulator): JsonResponse
    {
        $storeCode = $request->query->get('storeCode', '');
        if (!is_string($storeCode)) return $this->json(['message' => 'storeCode must be a string.'], 400);
        try {
            return $this->json(['code' => 0, 'data' => $simulator->currentSnapshot($storeCode), 'message' => 'Current campaigns loaded.']);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 400);
        }
    }

    #[Route('/api/v1/manage/promotion-simulation/orders/{id}', name: 'business-promotion-simulation-order', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function order(int $id, PromotionScenarioSimulator $simulator): JsonResponse
    {
        $data = $simulator->orderSnapshot($id);
        return $data === null
            ? $this->json(['message' => 'Order not found.'], 404)
            : $this->json(['code' => 0, 'data' => $data, 'message' => 'Order snapshot loaded.']);
    }

    #[Route('/api/v1/manage/promotion-simulation/users/{id}', name: 'business-promotion-simulation-user', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function user(int $id, PromotionScenarioSimulator $simulator): JsonResponse
    {
        $data = $simulator->userSnapshot($id);
        return $data === null
            ? $this->json(['message' => 'User not found.'], 404)
            : $this->json(['code' => 0, 'data' => $data, 'message' => 'User promotion context loaded.']);
    }

    #[Route('/api/v1/manage/promotion-simulation/run', name: 'business-promotion-simulation-run', methods: ['POST'])]
    public function run(Request $request, PromotionScenarioSimulator $simulator): JsonResponse
    {
        if (strlen($request->getContent()) > 500000) return $this->json(['message' => 'Simulation request exceeds 500000 bytes.'], 413);
        try {
            $data = $simulator->run($request->toArray());
        } catch (JsonException) {
            return $this->json(['message' => 'The request body must be a JSON object.'], 400);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 400);
        }

        // Read-only: no persist, flush, order creation or provider call.
        return $this->json(['code' => 0, 'data' => $data, 'message' => 'Scenario simulated; nothing saved.']);
    }
}
