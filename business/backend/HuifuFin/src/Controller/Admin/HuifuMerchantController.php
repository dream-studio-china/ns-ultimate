<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Service\HuifuMerchantService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-merchants', name: 'manage-huifu-merchants-')]
#[IsGranted('ROLE_ADMIN')]
final class HuifuMerchantController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'huifuId', 'providerUuid'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'huifuId', 'providerUuid', 'capabilities', 'enabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'huifuId', 'providerUuid', 'capabilities', 'enabled'];

    public function __construct(
        protected readonly HuifuMerchantService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof HuifuMerchant) {
            throw new \LogicException('Unexpected Huifu merchant entity.');
        }
        $provider = $this->provider($content['providerUuid'] ?? null);
        $content['provider'] = $provider->getId();
        unset($content['providerUuid']);
        if (array_key_exists('capabilities', $content) && !is_array($content['capabilities'])) {
            throw new \InvalidArgumentException('capabilities must be a list.');
        }

        return $content;
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof HuifuMerchant) {
            throw new \LogicException('Unexpected Huifu merchant entity.');
        }
        if (isset($content['huifuId']) && $content['huifuId'] !== $entity->getHuifuId()) {
            throw new \InvalidArgumentException('The Huifu merchant ID cannot be changed after creation.');
        }
        if (isset($content['providerUuid']) && $content['providerUuid'] !== $entity->getProviderUuid()) {
            throw new \InvalidArgumentException('The Huifu provider profile cannot be changed after creation.');
        }
        unset($content['huifuId'], $content['providerUuid']);
        if (array_key_exists('capabilities', $content) && !is_array($content['capabilities'])) {
            throw new \InvalidArgumentException('capabilities must be a list.');
        }

        return $content;
    }

    private function provider(mixed $uuid): HuifuProviderProfile
    {
        if (!is_string($uuid) || $uuid === '') {
            throw new \InvalidArgumentException('providerUuid is required.');
        }
        $provider = $this->entityManager->getRepository(HuifuProviderProfile::class)->findOneBy(['uuid' => $uuid]);
        if (!$provider instanceof HuifuProviderProfile) {
            throw new \InvalidArgumentException('Selected Huifu provider profile is invalid.');
        }

        return $provider;
    }
}
