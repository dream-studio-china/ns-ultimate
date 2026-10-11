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
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Service\WechatMerchantService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-merchants', name: 'manage-wechat-merchants-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatMerchantController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'merchantId'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'merchantId'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'enabled'];

    public function __construct(protected readonly WechatMerchantService $service)
    {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        $existing = $this->service->get(['merchantId' => trim((string) $content['merchantId'])], false);
        if ($existing instanceof WechatMerchant) {
            throw new \InvalidArgumentException('A WeChat merchant with this merchant ID already exists.');
        }

        return $content;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof WechatMerchant && $this->service->isBound($entity)) {
            return $this->warning('The merchant is still bound to a store and cannot be deleted.', 409, '', 409);
        }

        return null;
    }
}
