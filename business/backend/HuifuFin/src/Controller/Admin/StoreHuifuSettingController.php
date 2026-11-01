<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\StoreHuifuSettingService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/store-huifu-settings', name: 'manage-store-huifu-settings-')]
#[IsGranted('ROLE_ADMIN')]
final class StoreHuifuSettingController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['storeUuid'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['storeUuid', 'merchantUuid', 'enabled', 'allowedMethods', 'defaultMethod', 'posEnabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['storeUuid', 'merchantUuid', 'enabled', 'allowedMethods', 'defaultMethod', 'posEnabled'];

    public function __construct(
        protected readonly StoreHuifuSettingService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof StoreHuifuSetting) {
            throw new \LogicException('Unexpected Store Huifu setting entity.');
        }
        $storeUuid = $this->required($content, 'storeUuid');
        $this->assertStore($storeUuid);
        $entity->setStoreUuid($storeUuid);
        $this->applyMerchant($entity, $content);
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
        if (array_key_exists('storeUuid', $content) && $content['storeUuid'] !== $entity->getStoreUuid()) {
            throw new \InvalidArgumentException('storeUuid cannot be changed after creation.');
        }
        unset($content['storeUuid']);
        $this->applyMerchant($entity, $content);
        $this->applyMethods($entity, $content);

        return $content;
    }

    /** @param array<string, mixed> $content */
    private function applyMerchant(StoreHuifuSetting $setting, array &$content): void
    {
        if (!array_key_exists('merchantUuid', $content)) {
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
        if (!$merchant instanceof HuifuMerchant) {
            throw new \InvalidArgumentException('Selected Huifu merchant is invalid.');
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

    private function assertStore(string $storeUuid): void
    {
        if (!$this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]) instanceof Store) {
            throw new \InvalidArgumentException('Store not found.');
        }
    }

    /** @param array<string, mixed> $content */
    private function required(array $content, string $field): string
    {
        $value = $content[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }

        return trim($value);
    }
}
