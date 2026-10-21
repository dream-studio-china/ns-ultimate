<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\ApiViewMessages;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrinterJobRepositoryService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/jobs', name: 'manage-printer-jobs-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterJobController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(protected readonly PrinterJobRepositoryService $service) {}

    #[Route('/{id}/preview', name: 'preview', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function previewAction(int|string $id): Response
    {
        try {
            $this->authorizeApiAction('preview');
            $filter = $this->detailFilter($this->mixIdToCommonFilter($id));
            $job = $filter === null ? null : $this->detailProcessor($this->service->get($filter, false));
            if (!$job instanceof PrinterJob) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }

            return $this->success(['document' => $job->previewDocument(), 'copies' => $job->getCopies()]);
        } catch (AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        }
    }
}
