<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrinterDeviceService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/devices', name: 'manage-printer-devices-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterDeviceController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'model', 'storeUuid', 'widthMm', 'heightMm', 'enabled'];

    public function __construct(
        protected readonly PrinterDeviceService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    protected function processDeletion(object $entity): ?Response
    {
        if (!$entity instanceof PrinterDevice) {
            return $this->warning('Printer device not found.', 404, '', 404);
        }
        if ($this->entityManager->getRepository(PrinterJob::class)->count(['device' => $entity]) > 0) {
            return $this->warning('A printer device with print jobs cannot be deleted.', 409, '', 409);
        }
        return null;
    }
}
