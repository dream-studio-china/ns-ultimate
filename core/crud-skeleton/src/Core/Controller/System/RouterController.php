<?php

namespace App\Core\Controller\System;

use App\Core\Controller\RestController;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

#[Route('/system/router', name: 'system-router-')]
class RouterController extends RestController
{
    public function __construct(
        private readonly RouterInterface $router
    ) {}

    #[OA\Get(
        path: '/system/router',
        summary: 'List all registered routes',
        description: 'Returns all routes registered in the Symfony router, including internal routes.',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Route collection keyed by route name',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', description: 'Route name → Route object map'),
                        new OA\Property(property: 'code', type: 'integer', example: 0),
                        new OA\Property(property: 'message', type: 'string', example: 'SUCCESS'),
                    ]
                )
            ),
        ]
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $routes = $this->router->getRouteCollection()->all();

        return $this->success($routes);
    }
}
