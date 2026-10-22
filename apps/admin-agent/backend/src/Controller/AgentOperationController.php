<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Controller;

use NsUltimate\Apps\AdminAgent\Backend\Contract\ActorIdentityProvider;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/agent/operations', name: 'admin_agent_')]
final class AgentOperationController extends AbstractController
{
    public function __construct(private readonly AgentOperationService $operations, private readonly ActorIdentityProvider $identity) {}

    #[Route('', name: 'prepare', methods: ['POST'])]
    public function prepare(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 40000) throw new BadRequestHttpException('Proposal exceeds 40 KB.');
        $input = $request->toArray();
        return $this->json($this->operations->prepare($this->actor(), $input), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/{id}/commit', name: 'commit', methods: ['POST'], requirements: ['id' => '[a-f0-9]{64}'])]
    public function commit(string $id, Request $request): JsonResponse
    {
        if ($request->toArray() !== ['confirmed' => true]) throw new BadRequestHttpException('Explicit confirmation is required; replacement payloads are not accepted.');
        return $this->json($this->operations->commit($this->actor(), $id), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/{id}', name: 'status', methods: ['GET'], requirements: ['id' => '[a-f0-9]{64}'])]
    public function status(string $id): JsonResponse
    {
        return $this->json($this->operations->status($this->actor(), $id), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '[a-f0-9]{64}'])]
    public function cancel(string $id): JsonResponse
    {
        return $this->json($this->operations->cancel($this->actor(), $id), headers: ['Cache-Control' => 'no-store']);
    }

    private function actor(): string
    {
        return $this->identity->actorKey();
    }
}
