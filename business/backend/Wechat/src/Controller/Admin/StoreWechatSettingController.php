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
use App\Store\Repository\StoreRepository;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Service\StoreWechatSettingService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/store-wechat-settings', name: 'manage-store-wechat-settings-')]
#[IsGranted('ROLE_ADMIN')]
final class StoreWechatSettingController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['storeUuid'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['storeUuid', 'enabled', 'miniappRevision', 'officialRevision', 'merchantRevision', 'capabilities'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['enabled', 'miniappRevision', 'officialRevision', 'merchantRevision', 'capabilities'];

    public function __construct(
        protected readonly StoreWechatSettingService $service,
        private readonly StoreRepository $storeRepository,
    ) {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof StoreWechatSetting) {
            throw new \LogicException('Unexpected store WeChat setting entity.');
        }

        $storeUuid = trim((string) ($content['storeUuid'] ?? ''));
        if ($this->storeRepository->findOneByUuid($storeUuid) === null) {
            throw new \InvalidArgumentException('Store does not exist.');
        }
        if ($this->service->get(['storeUuid' => $storeUuid], false) instanceof StoreWechatSetting) {
            throw new \InvalidArgumentException('This store already has a WeChat setting.');
        }

        return $this->processBinding($entity, $content);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof StoreWechatSetting) {
            throw new \LogicException('Unexpected store WeChat setting entity.');
        }

        return $this->processBinding($entity, $content);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    private function processBinding(StoreWechatSetting $setting, array $content): array
    {
        $miniapp = array_key_exists('miniappRevision', $content)
            ? $this->service->resolveApplicationRevision($content['miniappRevision'], WechatApplication::TYPE_MINIAPP)
            : $setting->getMiniappRevision();
        $official = array_key_exists('officialRevision', $content)
            ? $this->service->resolveApplicationRevision($content['officialRevision'], WechatApplication::TYPE_OFFICIAL)
            : $setting->getOfficialRevision();
        $merchant = array_key_exists('merchantRevision', $content)
            ? $this->service->resolveMerchantRevision($content['merchantRevision'])
            : $setting->getMerchantRevision();

        $requested = array_key_exists('capabilities', $content)
            ? (array) $content['capabilities']
            : $setting->getCapabilities();
        $capabilities = $this->service->normalizeCapabilities($requested, $miniapp, $official, $merchant);

        $setting->setMiniappRevision($miniapp)
            ->setOfficialRevision($official)
            ->setMerchantRevision($merchant)
            ->setCapabilities($capabilities);

        unset($content['miniappRevision'], $content['officialRevision'], $content['merchantRevision'], $content['capabilities']);

        return $content;
    }
}
