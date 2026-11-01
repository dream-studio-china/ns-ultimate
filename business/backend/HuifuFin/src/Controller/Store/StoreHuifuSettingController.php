<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Store;

use App\Core\Controller\RestController;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchantStoreGrant;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\StoreHuifuSettingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/huifu-settings', name: 'huifu-store-settings-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class StoreHuifuSettingController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = [];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['merchantUuid', 'enabled', 'allowedMethods', 'defaultMethod', 'posEnabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['merchantUuid', 'enabled', 'allowedMethods', 'defaultMethod', 'posEnabled'];

    public function __construct(
        protected readonly StoreHuifuSettingService $service,
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
        return 'huifu_setting';
    }

    /** @return array<string, string> */
    protected function storeActionPermissions(): array
    {
        return ['options' => 'store:huifu_setting:read'];
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof StoreHuifuSetting) {
            throw new \LogicException('Unexpected Store Huifu setting entity.');
        }
        $store = $this->storeForAuthorization();
        $entity->setStoreUuid($store->getUuid());
        $content['storeUuid'] = $store->getUuid();
        $this->applyMerchant($entity, $content, $store->getUuid(), true);
        $this->applyMethods($entity, $content);

        return $content;
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof StoreHuifuSetting) {
            throw new \LogicException('Unexpected Store Huifu setting entity.');
        }
        unset($content['storeUuid']);
        $this->applyMerchant($entity, $content, $entity->getStoreUuid(), false);
        $this->applyMethods($entity, $content);

        return $content;
    }

    #[Route('/options', name: 'options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        $this->authorizeStoreAction('options');
        $store = $this->storeForAuthorization();
        $grants = $this->entityManager->getRepository(HuifuMerchantStoreGrant::class)
            ->findBy(['storeUuid' => $store->getUuid()], ['id' => 'ASC']);
        $merchants = [];
        foreach ($grants as $grant) {
            $merchant = $grant->getMerchant();
            if ($merchant instanceof HuifuMerchant && $merchant->isEnabled()) {
                $merchants[$merchant->getUuid()] = [
                    'id' => $merchant->getId(),
                    'uuid' => $merchant->getUuid(),
                    'name' => $merchant->getName(),
                    'huifuId' => $merchant->getHuifuId(),
                ];
            }
        }

        return $this->json(['merchants' => array_values($merchants)]);
    }

    /** @param array<string, mixed> $content */
    private function applyMerchant(StoreHuifuSetting $setting, array &$content, string $storeUuid, bool $creating): void
    {
        if (!array_key_exists('merchantUuid', $content)) {
            if ($creating) {
                throw new \InvalidArgumentException('merchantUuid is required.');
            }
            return;
        }
        $uuid = $content['merchantUuid'];
        unset($content['merchantUuid']);
        if ($uuid === null || $uuid === '') {
            $setting->setMerchant(null);
            return;
        }
        if (!is_string($uuid)) {
            throw new \InvalidArgumentException('merchantUuid is invalid.');
        }
        $merchant = $this->entityManager->getRepository(HuifuMerchant::class)->findOneBy(['uuid' => $uuid]);
        if (!$merchant instanceof HuifuMerchant || !$merchant->isEnabled()) {
            throw new \InvalidArgumentException('Selected Huifu merchant is invalid.');
        }
        $grant = $this->entityManager->getRepository(HuifuMerchantStoreGrant::class)
            ->findOneBy(['merchant' => $merchant, 'storeUuid' => $storeUuid]);
        if (!$grant instanceof HuifuMerchantStoreGrant) {
            throw new \InvalidArgumentException('The selected Huifu merchant is not granted to this Store.');
        }
        $setting->setMerchant($merchant);
    }

    /** @param array<string, mixed> $content */
    private function applyMethods(StoreHuifuSetting $setting, array &$content): void
    {
        if (array_key_exists('allowedMethods', $content)) {
            if (!is_array($content['allowedMethods'])) {
                throw new \InvalidArgumentException('allowedMethods must be a list.');
            }
            $setting->setAllowedMethods(array_values(array_filter($content['allowedMethods'], 'is_string')));
            unset($content['allowedMethods']);
        }
        if (array_key_exists('defaultMethod', $content)) {
            $setting->setDefaultMethod((string) $content['defaultMethod']);
            unset($content['defaultMethod']);
        }
    }
}
