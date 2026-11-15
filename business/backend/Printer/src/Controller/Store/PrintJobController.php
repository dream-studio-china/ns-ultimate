<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Store;

use App\Core\Controller\RestController;
use App\Core\View\ApiViewMessages;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrinterJobRepositoryService;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/print-jobs', name: 'store-print-jobs-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class PrintJobController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly PrinterJobRepositoryService $service,
        private readonly PrinterJobService $jobs,
        private readonly StoreServiceInterface $storeService,
    ) {
    }

    /** @return array<string, string> */
    protected function storeActionPermissions(): array
    {
        return [
            'list' => 'store:order:print',
            'detail' => 'store:order:print',
            'preview' => 'store:order:print',
            'reprint' => 'store:order:print',
        ];
    }

    protected function storeScopedFilter(Store $store): array
    {
        return ['storeUuid' => $store->getUuid()];
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->storeService;
    }

    protected function storeAuthorizationResource(): string
    {
        return 'order';
    }

    protected function listProcessor(mixed $entities): mixed
    {
        if ($entities instanceof QueryBuilder) {
            $entities->addOrderBy('entity.createdAt', 'DESC')->addOrderBy('entity.id', 'DESC');
        }

        return $entities;
    }

    #[Route('/{id}/preview', name: 'preview', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function previewAction(int|string $id): Response
    {
        try {
            $this->authorizeApiAction('preview');
            $job = $this->service->get($this->mixIdToCommonFilter($id), false);
            if (!$job instanceof PrinterJob) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }

            return $this->success(['document' => $job->previewDocument(), 'copies' => $job->getCopies()]);
        } catch (AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        }
    }

    #[Route('/{id}/reprint', name: 'reprint', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function reprintAction(int|string $id): Response
    {
        try {
            $this->authorizeApiAction('reprint');
            $job = $this->service->get($this->mixIdToCommonFilter($id), false);
            if (!$job instanceof PrinterJob) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $reprinted = $this->jobs->reprint($job);

            return $this->success($reprinted, 'SUCCESS', $reprinted->getStatus() === PrinterJob::STATUS_FAILED ? 422 : 201);
        } catch (AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        } catch (\DomainException $exception) {
            return $this->warning($exception->getMessage(), 409, '', 409);
        } catch (\Throwable) {
            return $this->warning('Unable to reprint job.', 502, '', 502);
        }
    }
}
