<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Controller;

use NsUltimate\Business\Promotion\Service\PromotionStackSimulator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PromotionStackController extends AbstractController
{
    #[Route('/api/v1/manage/promotion-stacking/check', name: 'business-promotion-stacking-check', methods: ['POST'])]
    public function check(Request $request, PromotionStackSimulator $simulator): JsonResponse
    {
        if (strlen($request->getContent()) > 64000) return $this->json(['message' => 'Simulation request exceeds 64000 bytes.'], 413);
        try {
            $input = $request->toArray();
            $result = $simulator->simulate($input);
        } catch (JsonException) {
            return $this->json(['message' => 'The request body must be a JSON object.'], 400);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 400);
        }

        // Simulation is read-only: no persist, flush, order creation or provider call.
        return $this->json(['code' => 0, 'data' => $result, 'message' => 'Stack simulated; nothing saved.']);
    }
}
