<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Repository\StoreRepository;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatProfileGrant;
use NsUltimate\Business\Wechat\Service\WechatAuditLogger;
use NsUltimate\Business\Wechat\Service\WechatProfileGrantService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administrator-managed grants that let a Store bind a shared profile.
 *
 * Grants are immutable; revoke by deleting and re-creating.
 */
#[Route('/manage/wechat-profile-grants', name: 'manage-wechat-profile-grants-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatProfileGrantController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['profileType', 'profileUuid', 'storeUuid'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['profileType', 'profileUuid', 'storeUuid'];

    public function __construct(
        protected readonly WechatProfileGrantService $service,
        private readonly StoreRepository $storeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatAuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        $profileType = (string) ($content['profileType'] ?? '');
        $profileUuid = (string) ($content['profileUuid'] ?? '');
        $storeUuid = (string) ($content['storeUuid'] ?? '');

        if ($this->storeRepository->findOneByUuid($storeUuid) === null) {
            throw new \InvalidArgumentException('Store does not exist.');
        }

        $profileExists = match ($profileType) {
            WechatProfileGrant::TYPE_APPLICATION => $this->entityManager->getRepository(WechatApplication::class)->findOneBy(['uuid' => $profileUuid]) !== null,
            WechatProfileGrant::TYPE_MERCHANT => $this->entityManager->getRepository(WechatMerchant::class)->findOneBy(['uuid' => $profileUuid]) !== null,
            default => throw new \InvalidArgumentException('profileType must be application or merchant.'),
        };
        if (!$profileExists) {
            throw new \InvalidArgumentException('The referenced profile does not exist.');
        }

        return $content;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        if ($entity instanceof WechatProfileGrant) {
            $this->audit->record('grant-create', 'WechatProfileGrant', $entity->getUuid(), 'success', $entity->getStoreUuid(), [
                'profileType' => $entity->getProfileType(),
                'profileUuid' => $entity->getProfileUuid(),
            ]);
        }

        return $entity;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof WechatProfileGrant) {
            $this->audit->record('grant-delete', 'WechatProfileGrant', $entity->getUuid(), 'success', $entity->getStoreUuid(), [
                'profileType' => $entity->getProfileType(),
                'profileUuid' => $entity->getProfileUuid(),
            ]);
        }

        return null;
    }
}
