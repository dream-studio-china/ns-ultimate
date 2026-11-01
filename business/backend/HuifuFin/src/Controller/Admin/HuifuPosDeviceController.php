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
use NsUltimate\Business\HuifuFin\Entity\HuifuPosDevice;
use NsUltimate\Business\HuifuFin\Service\HuifuPosDeviceService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-pos-devices', name: 'manage-huifu-pos-devices-')]
#[IsGranted('ROLE_ADMIN')]
final class HuifuPosDeviceController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['merchantUuid', 'storeUuid', 'terminalId'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['merchantUuid', 'storeUuid', 'terminalId', 'mode', 'description', 'enabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['merchantUuid', 'storeUuid', 'terminalId', 'mode', 'description', 'enabled'];

    public function __construct(
        protected readonly HuifuPosDeviceService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof HuifuPosDevice) {
            throw new \LogicException('Unexpected Huifu POS device entity.');
        }
        $merchantUuid = $content['merchantUuid'] ?? null;
        $merchant = is_string($merchantUuid)
            ? $this->entityManager->getRepository(HuifuMerchant::class)->findOneBy(['uuid' => $merchantUuid])
            : null;
        if (!$merchant instanceof HuifuMerchant) {
            throw new \InvalidArgumentException('Selected Huifu merchant is invalid.');
        }
        $storeUuid = $content['storeUuid'] ?? null;
        if (!is_string($storeUuid) || !$this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]) instanceof Store) {
            throw new \InvalidArgumentException('Store not found.');
        }
        $content['merchant'] = $merchant->getId();
        $content['storeUuid'] = $storeUuid;
        unset($content['merchantUuid']);

        return $content;
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof HuifuPosDevice) {
            throw new \LogicException('Unexpected Huifu POS device entity.');
        }
        foreach (['merchantUuid' => $entity->getMerchantUuid(), 'storeUuid' => $entity->getStoreUuid(), 'terminalId' => $entity->getTerminalId()] as $field => $current) {
            if (array_key_exists($field, $content) && $content[$field] !== $current) {
                throw new \InvalidArgumentException(sprintf('%s cannot be changed after creation.', $field));
            }
            unset($content[$field]);
        }
        if (isset($content['merchant']) && (string) $content['merchant'] !== (string) $entity->getMerchant()?->getId()) {
            throw new \InvalidArgumentException('merchant cannot be changed after creation.');
        }
        unset($content['merchant']);

        return $content;
    }
}
