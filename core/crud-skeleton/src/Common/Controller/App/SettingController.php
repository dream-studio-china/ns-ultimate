<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\SettingServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/settings', name: 'app-settings-')]
class SettingController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly SettingServiceInterface $service
    ) {}
}
