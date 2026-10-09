<?php

namespace App\Core\View;

use App\Core\Query\DqlExpression;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait DetailApiViewMixin
{
    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|DqlExpression|null
     */
    protected function detailFilter(array|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter = null)
    {
        /** list filter for list entities */
        return $filter;
    }

    protected function detailProcessor(?object $entity): ?object
    {
        /** detail processor */
        return $entity;
    }

    protected function detailResponse(?object $entity): mixed
    {
        /** detail response */
        return $entity;
    }

    #[OA\Get(
        tags: ['Detail'],
        parameters: [
            new OA\Parameter(name: '@expands', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api detail view'),
        ]
    )]
    #[Route('/{id}', name: 'detail', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function detailAction(int|string $id): Response
    {
        try {
            $this->authorizeApiAction('detail');
            $service = $this->service;
            $filter = $this->mixIdToCommonFilter($id);
            $filter = $this->detailFilter($filter);
            if ($filter === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $entity = $this->detailProcessor(
                $service->get($filter, false)
            );
            $response = $this->detailResponse($entity);

            return $response ?
                $this->success($response):
                $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        }
    }
}
