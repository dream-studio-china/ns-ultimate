<?php

namespace App\Core\View;

use App\Core\Query\DqlExpression;
use App\Core\Service\BaseService;
use Doctrine\Common\Collections\ArrayCollection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait ListApiViewMixin
{
    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|DqlExpression|null
     */
    protected function listFilter(array|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter = null)
    {
        /** list filter for list entities */
        return $filter;
    }

    protected function listProcessor(mixed $entities): mixed
    {
        /** list processor for list entities */
        return $entities;
    }

    protected function listResponses(mixed $entities): mixed
    {
        /** list responses for list entities */
        return $entities;
    }

    #[OA\Get(
        tags: ['List'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@order', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@dql', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@select', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@groupBy', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@hints', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@filter', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@expands', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@display', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@showDQL', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api list view'),
        ]
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        try {
            $this->authorizeApiAction('list');
            $service = $this->service;
            $filter = $this->listFilter($this->resolvedCommonFilter());
            $entities = $this->listProcessor(
                $service->list($filter, null, false)
            );
            $entities = $this->listResponses($entities);
            return $this->success($entities);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        }
    }
}
