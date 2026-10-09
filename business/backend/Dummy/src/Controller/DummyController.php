<?php

declare(strict_types=1);

namespace NsUltimate\Business\Dummy\Controller;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use NsUltimate\Business\Dummy\Service\DummyService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/dummies', name: 'business-dummies-')]
#[IsGranted('ROLE_ADMIN')]
final class DummyController extends RestController
{
    use ApiView, CreateApiViewMixin, DeleteApiViewMixin, DetailApiViewMixin, ListApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['title'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['title', 'body'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['title', 'body'];

    public function __construct(protected readonly DummyService $service)
    {
    }
}
