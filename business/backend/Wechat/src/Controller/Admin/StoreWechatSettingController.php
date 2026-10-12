<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Service\StoreWechatSettingService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/store-wechat-settings', name: 'wechat-store-settings-')]
#[IsGranted('ROLE_ADMIN')]
final class StoreWechatSettingController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    protected array $requiredCreateProperties = ['storeUuid', 'merchantUuid'];
    protected array $acceptedCreateProperties = ['storeUuid', 'miniappUuid', 'officialUuid', 'merchantUuid'];
    protected array $acceptedUpdateProperties = ['storeUuid', 'miniappUuid', 'officialUuid', 'merchantUuid'];

    public function __construct(
        protected readonly StoreWechatSettingService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof StoreWechatSetting) {
            throw new \LogicException('Unexpected store WeChat setting entity.');
        }
        return $this->processSettingContent($content, $entity, true);
    }

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
        $storeUuid = $content['storeUuid'] ?? ($creating ? null : $setting->getStoreUuid());
        if (!is_string($storeUuid) || trim($storeUuid) === '') {
            throw new \InvalidArgumentException('storeUuid is required.');
        }
        $storeUuid = trim($storeUuid);
        if (!$this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]) instanceof Store) {
            throw new \InvalidArgumentException('Store not found.');
        }
        if (!$creating && $storeUuid !== $setting->getStoreUuid()) {
            throw new \InvalidArgumentException('storeUuid cannot be changed after creation.');
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
            $merchant = is_string($content['merchantUuid'])
                ? $this->entityManager->getRepository(WechatMerchant::class)->findOneBy(['uuid' => $content['merchantUuid']])
                : null;
            if (!$merchant instanceof WechatMerchant) {
                throw new \InvalidArgumentException('Selected WeChat merchant is invalid.');
            }
            $content['merchant'] = $merchant->getId();
            unset($content['merchantUuid']);
        } elseif ($creating) {
            throw new \InvalidArgumentException('merchantUuid is required.');
        }

        $miniappId = array_key_exists('miniapp', $applicationIds) ? $applicationIds['miniapp'] : $setting->getMiniapp()?->getId();
        $officialId = array_key_exists('official', $applicationIds) ? $applicationIds['official'] : $setting->getOfficial()?->getId();
        if ($miniappId === null && $officialId === null) {
            throw new \InvalidArgumentException('Select at least one WeChat application.');
        }

        if ($creating || array_key_exists('storeUuid', $content)) {
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
}
