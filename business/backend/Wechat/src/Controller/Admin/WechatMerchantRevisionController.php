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
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Service\WechatMerchantRevisionService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-merchant-revisions', name: 'business-wechat-merchant-revisions-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatMerchantRevisionController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['merchant', 'apiV3Key', 'privateKey', 'certificate'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['merchant', 'apiV3Key', 'privateKey', 'certificate', 'platformPublicKeyId', 'platformPublicKey'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['state'];

    public function __construct(
        protected readonly WechatMerchantRevisionService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof WechatMerchantRevision) {
            throw new \LogicException('Unexpected merchant revision entity.');
        }

        $merchant = $this->entityManager
            ->getRepository(WechatMerchant::class)
            ->find((int) $content['merchant']);
        if (!$merchant instanceof WechatMerchant) {
            throw new \InvalidArgumentException('The merchant was not found.');
        }

        $entity->setMerchant($merchant)->setRevision($this->service->nextRevision($merchant));
        $this->service->applyCredentials($entity, $content);

        unset(
            $content['merchant'],
            $content['apiV3Key'],
            $content['privateKey'],
            $content['certificate'],
            $content['platformPublicKeyId'],
            $content['platformPublicKey'],
        );

        return $content;
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!array_key_exists('state', $content)) {
            return $content;
        }
        if (!$entity instanceof WechatMerchantRevision) {
            throw new \LogicException('Unexpected merchant revision entity.');
        }
        if ($content['state'] !== WechatMerchantRevision::STATE_PUBLISHED) {
            throw new \InvalidArgumentException('Only the "published" state transition is supported.');
        }

        $this->service->publish($entity);
        unset($content['state']);

        return $content;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof WechatMerchantRevision && $this->service->isBound($entity)) {
            return $this->warning('The revision is still bound to a store and cannot be deleted.', 409, '', 409);
        }

        return null;
    }
}
