<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Store;

use App\Core\Controller\RestController;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatProfileGrant;
use NsUltimate\Business\Wechat\Service\StoreWechatSettingService;
use NsUltimate\Business\Wechat\Service\WechatProfileGrantService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/wechat-setting', name: 'store-wechat-setting-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class WechatSettingController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['enabled', 'miniappRevision', 'officialRevision', 'merchantRevision', 'capabilities'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['enabled', 'miniappRevision', 'officialRevision', 'merchantRevision', 'capabilities'];

    public function __construct(
        protected readonly StoreWechatSettingService $service,
        private readonly StoreServiceInterface $storeService,
        private readonly WechatProfileGrantService $grants,
    ) {
    }

    /** @return array<string, mixed> */
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
        return 'wechat';
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof StoreWechatSetting) {
            throw new \LogicException('Unexpected store WeChat setting entity.');
        }

        $store = $this->storeForAuthorization();
        if ($this->service->get(['storeUuid' => $store->getUuid()], false) instanceof StoreWechatSetting) {
            throw new \InvalidArgumentException('This store already has a WeChat setting.');
        }
        $entity->setStoreUuid($store->getUuid());

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
        $storeUuid = $setting->getStoreUuid();

        $miniapp = array_key_exists('miniappRevision', $content)
            ? $this->service->resolveApplicationRevision($content['miniappRevision'], WechatApplication::TYPE_MINIAPP)
            : $setting->getMiniappRevision();
        if (array_key_exists('miniappRevision', $content) && $miniapp !== null) {
            $this->grants->assertGranted($storeUuid, WechatProfileGrant::TYPE_APPLICATION, (string) $miniapp->getApplication()?->getUuid());
        }

        $official = array_key_exists('officialRevision', $content)
            ? $this->service->resolveApplicationRevision($content['officialRevision'], WechatApplication::TYPE_OFFICIAL)
            : $setting->getOfficialRevision();
        if (array_key_exists('officialRevision', $content) && $official !== null) {
            $this->grants->assertGranted($storeUuid, WechatProfileGrant::TYPE_APPLICATION, (string) $official->getApplication()?->getUuid());
        }

        $merchant = array_key_exists('merchantRevision', $content)
            ? $this->service->resolveMerchantRevision($content['merchantRevision'])
            : $setting->getMerchantRevision();
        if (array_key_exists('merchantRevision', $content) && $merchant !== null) {
            $this->grants->assertGranted($storeUuid, WechatProfileGrant::TYPE_MERCHANT, (string) $merchant->getMerchant()?->getUuid());
        }

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
