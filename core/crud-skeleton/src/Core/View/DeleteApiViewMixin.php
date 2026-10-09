<?php

namespace App\Core\View;

use App\Core\Query\DqlExpression;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait DeleteApiViewMixin
{
    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|DqlExpression|null
     */
    protected function deletionFilter(array|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter = null)
    {
        /** list filter for list entities */
        return $filter;
    }

    protected function processDeletion(object $entity): ?Response
    {
        return null;
    }

    #[OA\Delete(
        tags: ['Delete'],
        responses: [
            new OA\Response(response: 200, description: 'Api delete view'),
        ]
    )]
    #[Route('/{id}', name: 'delete', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function deleteAction(int|string $id): Response
    {
        try {
            $this->authorizeApiAction('delete');
            $service = $this->service;
            $filter = $this->mixIdToCommonFilter($id);
            $filter = $this->deletionFilter($filter);
            if ($filter === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $entity = $service->get($filter, false);

            if (!$entity) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $this->authorizeApiAction('delete', $entity);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        }

        if (($response = $this->processDeletion($entity)) !== null) {
            return $response;
        }

        return $service->remove($entity) ?
            $this->success('', ApiViewMessages::SUCCESS, 204) : $this->warning();
    }
}
