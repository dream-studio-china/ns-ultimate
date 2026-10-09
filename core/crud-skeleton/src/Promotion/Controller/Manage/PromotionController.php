<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Promotion\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Promotion\Service\PromotionServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/promotions', name: 'manage-promotions-')]
#[IsGranted('ROLE_ADMIN')]
class PromotionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'template'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = [
        'name', 'description', 'template', 'storeCode',
        'enabled', 'startTime', 'endTime', 'config', 'conflictMode',
    ];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = [
        'name', 'description', 'template', 'storeCode',
        'enabled', 'startTime', 'endTime', 'config', 'conflictMode',
    ];

    public function __construct(
        protected readonly PromotionServiceInterface $service,
    ) {}
}
