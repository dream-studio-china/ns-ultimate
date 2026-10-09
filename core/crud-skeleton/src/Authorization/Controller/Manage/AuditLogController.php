<?php

declare(strict_types=1);

namespace App\Authorization\Controller\Manage;

use App\Authorization\Service\AuditLogService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/audit-logs', name: 'manage-audit-logs-')]
#[IsGranted('ROLE_ADMIN')]
class AuditLogController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly AuditLogService $service,
    ) {
    }

    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null
     */
    protected function listFilter(array|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter = null): array|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null
    {
        $request = $this->getRequestStack()->getCurrentRequest();
        if ($request === null) {
            return $filter;
        }
        $targetType = $request->query->get('targetType');
        $actorUuid = $request->query->get('actorUuid');
        $criteria = [];
        if (\is_string($targetType) && $targetType !== '') {
            $criteria['targetType'] = $targetType;
        }
        if (\is_string($actorUuid) && $actorUuid !== '') {
            $criteria['actorUuid'] = $actorUuid;
        }
        if ($filter instanceof \Doctrine\ORM\QueryBuilder) {
            foreach ($criteria as $k => $v) {
                $alias = $filter->getRootAliases()[0];
                $filter->andWhere("$alias.$k = :$k")->setParameter($k, $v);
            }
            return $filter;
        }
        if (\is_array($filter)) {
            return array_merge($criteria, $filter);
        }

        return $criteria;
    }
}
