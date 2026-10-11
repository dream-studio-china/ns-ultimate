<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Service\WechatApplicationService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-applications', name: 'manage-wechat-applications-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatApplicationController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'type', 'appId'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'type', 'appId'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'enabled'];

    public function __construct(protected readonly WechatApplicationService $service)
    {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        $existing = $this->service->get(['appId' => trim((string) $content['appId'])], false);
        if ($existing instanceof WechatApplication) {
            throw new \InvalidArgumentException('A WeChat application with this AppID already exists.');
        }

        return $content;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof WechatApplication && $this->service->isBound($entity)) {
            return $this->warning('The application is still bound to a store and cannot be deleted.', 409, '', 409);
        }

        return null;
    }
}
