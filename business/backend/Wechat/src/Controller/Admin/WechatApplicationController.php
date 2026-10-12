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
use NsUltimate\Business\Wechat\Service\WechatApplicationService;
use NsUltimate\Business\Wechat\Service\WechatApplicationRevisionService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-applications', name: 'manage-wechat-applications-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatApplicationController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'type', 'appId'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'type', 'appId', 'secret', 'officialToken', 'officialAesKey', 'redirectHosts'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'enabled', 'secret', 'officialToken', 'officialAesKey', 'redirectHosts'];

    /** @var \WeakMap<object, array<string, mixed>> */
    private \WeakMap $pendingCredentials;

    public function __construct(
        protected readonly WechatApplicationService $service,
        private readonly WechatApplicationRevisionService $revisions,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->pendingCredentials = new \WeakMap();
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        $existing = $this->service->get(['appId' => trim((string) $content['appId'])], false);
        if ($existing instanceof WechatApplication) {
            throw new \InvalidArgumentException('A WeChat application with this AppID already exists.');
        }

        if ($this->hasSecret($content['secret'] ?? null)) {
            $this->pendingCredentials[$entity] = $this->credentials($content);
        }
        $this->removeCredentialFields($content);

        return $content;
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if ($this->hasSecret($content['secret'] ?? null)) {
            if (!$entity instanceof WechatApplication) {
                throw new \LogicException('Unexpected application entity.');
            }
            $this->createRevision($entity, $this->credentials($content));
        }
        $this->removeCredentialFields($content);

        return $content;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        if ($entity instanceof WechatApplication && isset($this->pendingCredentials[$entity])) {
            $this->createRevision($entity, $this->pendingCredentials[$entity]);
            unset($this->pendingCredentials[$entity]);
        }

        return $this->after($entity);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    private function credentials(array $content): array
    {
        return array_intersect_key($content, array_flip(['secret', 'officialToken', 'officialAesKey', 'redirectHosts']));
    }

    private function removeCredentialFields(array &$content): void
    {
        foreach (['secret', 'officialToken', 'officialAesKey', 'redirectHosts'] as $field) {
            unset($content[$field]);
        }
    }

    private function hasSecret(mixed $secret): bool
    {
        return is_string($secret) && trim($secret) !== '';
    }

    /** @param array<string, mixed> $credentials */
    private function createRevision(WechatApplication $application, array $credentials): void
    {
        $revision = (new WechatApplicationRevision())
            ->setApplication($application)
            ->setRevision($this->revisions->nextRevision($application));
        $this->revisions->applyCredentials($revision, $credentials);
        $this->entityManager->persist($revision);
        $this->revisions->publish($revision);
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof WechatApplication && $this->service->isBound($entity)) {
            return $this->warning('The application is still bound to a store and cannot be deleted.', 409, '', 409);
        }

        return null;
    }
}
