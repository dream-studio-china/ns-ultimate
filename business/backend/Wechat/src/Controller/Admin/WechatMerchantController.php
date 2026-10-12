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
use NsUltimate\Business\Wechat\Service\WechatMerchantService;
use NsUltimate\Business\Wechat\Service\WechatMerchantRevisionService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-merchants', name: 'manage-wechat-merchants-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatMerchantController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'merchantId'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'merchantId', 'mode', 'apiV3Key', 'privateKey', 'certificate', 'platformPublicKeyId', 'platformPublicKey'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'enabled', 'mode', 'apiV3Key', 'privateKey', 'certificate', 'platformPublicKeyId', 'platformPublicKey'];

    /** @var \WeakMap<object, array<string, mixed>> */
    private \WeakMap $pendingCredentials;

    public function __construct(
        protected readonly WechatMerchantService $service,
        private readonly WechatMerchantRevisionService $revisions,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->pendingCredentials = new \WeakMap();
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        $existing = $this->service->get(['merchantId' => trim((string) $content['merchantId'])], false);
        if ($existing instanceof WechatMerchant) {
            throw new \InvalidArgumentException('A WeChat merchant with this merchant ID already exists.');
        }

        if ($this->hasSecret($content['apiV3Key'] ?? null)) {
            $this->pendingCredentials[$entity] = $this->credentials($content);
        }
        $this->removeCredentialFields($content);

        return $content;
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (array_key_exists('mode', $content)
            && $entity instanceof WechatMerchant
            && $content['mode'] !== $entity->getMode()
            && !$this->hasSecret($content['apiV3Key'] ?? null)) {
            throw new \InvalidArgumentException('Changing merchant mode requires replacing its credentials.');
        }
        if ($this->hasSecret($content['apiV3Key'] ?? null)) {
            if (!$entity instanceof WechatMerchant) {
                throw new \LogicException('Unexpected merchant entity.');
            }
            $this->createRevision($entity, $this->credentials($content));
        }
        $this->removeCredentialFields($content);

        return $content;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        if ($entity instanceof WechatMerchant && isset($this->pendingCredentials[$entity])) {
            $this->createRevision($entity, $this->pendingCredentials[$entity]);
            unset($this->pendingCredentials[$entity]);
        }

        return $this->after($entity);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    private function credentials(array $content): array
    {
        $credentials = array_intersect_key($content, array_flip(['mode', 'apiV3Key', 'privateKey', 'certificate', 'platformPublicKeyId', 'platformPublicKey']));
        foreach (['platformPublicKeyId', 'platformPublicKey'] as $optional) {
            if (isset($credentials[$optional]) && is_string($credentials[$optional]) && trim($credentials[$optional]) === '') {
                unset($credentials[$optional]);
            }
        }

        return $credentials;
    }

    private function removeCredentialFields(array &$content): void
    {
        foreach (['apiV3Key', 'privateKey', 'certificate', 'platformPublicKeyId', 'platformPublicKey'] as $field) {
            unset($content[$field]);
        }
    }

    private function hasSecret(mixed $secret): bool
    {
        return is_string($secret) && trim($secret) !== '';
    }

    /** @param array<string, mixed> $credentials */
    private function createRevision(WechatMerchant $merchant, array $credentials): void
    {
        $revision = (new WechatMerchantRevision())
            ->setMerchant($merchant)
            ->setRevision($this->revisions->nextRevision($merchant));
        $this->revisions->applyCredentials($revision, $credentials);
        $this->entityManager->persist($revision);
        $this->revisions->publish($revision);
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof WechatMerchant && $this->service->isBound($entity)) {
            return $this->warning('The merchant is still bound to a store and cannot be deleted.', 409, '', 409);
        }

        return null;
    }
}
