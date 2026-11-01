<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchantStoreGrant;
use NsUltimate\Business\HuifuFin\Service\HuifuMerchantStoreGrantService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-merchant-store-grants', name: 'manage-huifu-merchant-store-grants-')]
#[IsGranted('ROLE_ADMIN')]
final class HuifuMerchantStoreGrantController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['merchantUuid', 'storeUuid'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['merchantUuid', 'storeUuid'];

    public function __construct(
        protected readonly HuifuMerchantStoreGrantService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof HuifuMerchantStoreGrant) {
            throw new \LogicException('Unexpected Huifu Store grant entity.');
        }
        $merchantUuid = $content['merchantUuid'] ?? null;
        $storeUuid = $content['storeUuid'] ?? null;
        $merchant = is_string($merchantUuid)
            ? $this->entityManager->getRepository(HuifuMerchant::class)->findOneBy(['uuid' => $merchantUuid])
            : null;
        if (!$merchant instanceof HuifuMerchant) {
            throw new \InvalidArgumentException('Selected Huifu merchant is invalid.');
        }
        if (!is_string($storeUuid) || !$this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]) instanceof Store) {
            throw new \InvalidArgumentException('Store not found.');
        }
        $content['merchant'] = $merchant->getId();
        $content['storeUuid'] = $storeUuid;
        unset($content['merchantUuid']);

        return $content;
    }
}
