<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait SingleDetailApiViewMixin
{
    #[OA\Get(
        tags: ['Detail'],
        parameters: [
            new OA\Parameter(name: '@expands', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api single detail view'),
        ]
    )]
    #[Route('', name: 'detail', methods: ['GET'])]
    public function detailAction(): Response
    {
        $service = $this->service;
        $filter = $this->resolvedCommonFilter();
        $entity = $service->get($filter, false);

        return $this->success($entity);
    }
}
