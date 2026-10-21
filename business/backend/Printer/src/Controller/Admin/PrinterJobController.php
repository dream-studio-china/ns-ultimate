<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use NsUltimate\Business\Printer\Service\PrinterJobRepositoryService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/jobs', name: 'manage-printer-jobs-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterJobController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(protected readonly PrinterJobRepositoryService $service) {}
}
