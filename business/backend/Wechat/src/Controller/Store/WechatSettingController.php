<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Store;

use App\Core\Controller\RestController;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Service\StoreWechatSettingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/wechat-settings', name: 'wechat-store-settings-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class WechatSettingController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = [];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['miniappUuid', 'officialUuid', 'merchantUuid'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['miniappUuid', 'officialUuid', 'merchantUuid'];

    public function __construct(
        protected readonly StoreWechatSettingService $service,
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreServiceInterface $storeService,
    ) {
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
        return 'wechat_setting';
    }

    /** @return array<string, string> */
    protected function storeActionPermissions(): array
    {
        return ['options' => 'store:wechat_setting:read'];
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof StoreWechatSetting) {
            throw new \LogicException('Unexpected store WeChat setting entity.');
        }
        $content['storeUuid'] = $this->storeForAuthorization()->getUuid();
        return $this->processSettingContent($content, $entity, true);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof StoreWechatSetting) {
            throw new \LogicException('Unexpected store WeChat setting entity.');
        }
        return $this->processSettingContent($content, $entity, false);
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed>
     */
    private function processSettingContent(array $content, StoreWechatSetting $setting, bool $creating): array
    {
        $storeUuid = $this->storeForAuthorization()->getUuid();
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        if (!$store instanceof Store) {
            throw new \InvalidArgumentException('Store not found.');
        }

        $applicationIds = [];
        foreach (['miniappUuid' => ['miniapp', WechatApplication::TYPE_MINIAPP], 'officialUuid' => ['official', WechatApplication::TYPE_OFFICIAL]] as $field => [$property, $type]) {
            if (array_key_exists($field, $content)) {
                $application = $this->application($content[$field], $type);
                $applicationIds[$property] = $application?->getId();
                unset($content[$field]);
            } elseif ($creating) {
                $applicationIds[$property] = null;
            }
        }

        if (array_key_exists('merchantUuid', $content)) {
            $merchantUuid = $content['merchantUuid'];
            $merchant = is_string($merchantUuid) && $merchantUuid !== ''
                ? $this->entityManager->getRepository(WechatMerchant::class)->findOneBy(['uuid' => $content['merchantUuid']])
                : null;
            if ($merchantUuid !== null && $merchantUuid !== '' && !$merchant instanceof WechatMerchant) {
                throw new \InvalidArgumentException('Selected WeChat merchant is invalid.');
            }
            $content['merchant'] = $merchant?->getId();
            unset($content['merchantUuid']);
        } elseif ($creating) {
            $content['merchant'] = null;
        }

        $miniappId = array_key_exists('miniapp', $applicationIds) ? $applicationIds['miniapp'] : $setting->getMiniapp()?->getId();
        $officialId = array_key_exists('official', $applicationIds) ? $applicationIds['official'] : $setting->getOfficial()?->getId();
        if ($miniappId === null && $officialId === null) {
            throw new \InvalidArgumentException('Select at least one WeChat application.');
        }
        if ($creating) {
            $content['storeUuid'] = $storeUuid;
        }
        return array_merge($content, $applicationIds);
    }

    private function application(mixed $uuid, string $type): ?WechatApplication
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $application = is_string($uuid)
            ? $this->entityManager->getRepository(WechatApplication::class)->findOneBy(['uuid' => $uuid])
            : null;
        if (!$application instanceof WechatApplication || $application->getType() !== $type) {
            throw new \InvalidArgumentException('Selected WeChat application is invalid.');
        }
        return $application;
    }

    #[Route('/options', name: 'options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        $this->authorizeStoreAction('options');
        return $this->json([
            'applications' => array_map($this->applicationRow(...), $this->entityManager->getRepository(WechatApplication::class)->findBy([], ['name' => 'ASC'])),
            'merchants' => array_map($this->merchantRow(...), $this->entityManager->getRepository(WechatMerchant::class)->findBy([], ['name' => 'ASC'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function applicationRow(WechatApplication $application): array
    {
        return ['id' => $application->getId(), 'uuid' => $application->getUuid(), 'name' => $application->getName(), 'type' => $application->getType(), 'appId' => $application->getAppId()];
    }

    /** @return array<string, mixed> */
    private function merchantRow(WechatMerchant $merchant): array
    {
        return ['id' => $merchant->getId(), 'uuid' => $merchant->getUuid(), 'name' => $merchant->getName(), 'merchantId' => $merchant->getMerchantId()];
    }
}
