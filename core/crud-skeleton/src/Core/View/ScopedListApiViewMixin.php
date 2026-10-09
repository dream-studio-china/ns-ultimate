<?php

declare(strict_types=1);

namespace App\Core\View;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait ScopedListApiViewMixin
{
    /** @return array<string, mixed>|\Doctrine\ORM\QueryBuilder */
    abstract protected function scopedListFilter(string $scopeId): array|\Doctrine\ORM\QueryBuilder;

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(string $scopeId): Response
    {
        try {
            $this->authorizeApiAction('list');
            return $this->success($this->service->list($this->scopedListFilter($scopeId), null, false));
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        }
    }
}
