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
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Service\WechatApplicationRevisionService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-application-revisions', name: 'business-wechat-application-revisions-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatApplicationRevisionController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['application', 'secret'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['application', 'secret', 'officialToken', 'officialAesKey', 'redirectHosts'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['state'];

    public function __construct(
        protected readonly WechatApplicationRevisionService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof WechatApplicationRevision) {
            throw new \LogicException('Unexpected application revision entity.');
        }

        $application = $this->entityManager
            ->getRepository(WechatApplication::class)
            ->find((int) $content['application']);
        if (!$application instanceof WechatApplication) {
            throw new \InvalidArgumentException('The application was not found.');
        }

        $entity->setApplication($application)->setRevision($this->service->nextRevision($application));
        $this->service->applyCredentials($entity, $content);

        unset(
            $content['application'],
            $content['secret'],
            $content['officialToken'],
            $content['officialAesKey'],
            $content['redirectHosts'],
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
        if (!$entity instanceof WechatApplicationRevision) {
            throw new \LogicException('Unexpected application revision entity.');
        }
        if ($content['state'] !== WechatApplicationRevision::STATE_PUBLISHED) {
            throw new \InvalidArgumentException('Only the "published" state transition is supported.');
        }

        $this->service->publish($entity);
        unset($content['state']);

        return $content;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof WechatApplicationRevision && $this->service->isBound($entity)) {
            return $this->warning('The revision is still bound to a store and cannot be deleted.', 409, '', 409);
        }

        return null;
    }
}
