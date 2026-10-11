<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Store;

use App\Core\Controller\RestController;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\StoreWechatSettingRevision;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;
use NsUltimate\Business\Wechat\Service\StoreWechatBindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Store-scoped management of a single Store's WeChat binding.
 *
 * The Store is resolved from the scope route parameter, never from request data.
 */
#[Route('/store/{scopeId}/wechat-setting', name: 'store-wechat-setting-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class WechatSettingController extends RestController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreWechatBindingService $bindingService,
        private readonly StoreServiceInterface $storeService,
    ) {
    }

    #[Route('', name: 'detail', methods: ['GET'])]
    public function detailAction(): Response
    {
        $store = $this->authorizeStore('read');
        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['storeUuid' => $store->getUuid()]);

        if (!$setting instanceof StoreWechatSetting) {
            throw new NotFoundHttpException('Store WeChat setting not found.');
        }

        return $this->success($this->serializeDetail($setting));
    }

    #[Route('', name: 'update', methods: ['PUT'])]
    public function updateAction(Request $request): Response
    {
        $store = $this->authorizeStore('update');
        $setting = $this->bindingService->settingForStore($store->getUuid());
        $data = $this->jsonBody($request);

        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                throw new WechatValidationException('enabled must be a boolean.');
            }
            $setting->setEnabled($data['enabled']);
            $this->entityManager->flush();
        }

        return $this->success($this->serializeDetail($setting));
    }

    #[Route('/revisions', name: 'create-revision', methods: ['POST'])]
    public function createRevisionAction(Request $request): Response
    {
        $store = $this->authorizeStore('update');
        $setting = $this->bindingService->settingForStore($store->getUuid());
        $revision = $this->bindingService->createRevision($setting, $this->jsonBody($request));

        return $this->success($this->serializeRevision($revision), 'SUCCESS', 201);
    }

    #[Route('/revisions/{revisionUuid}/publish', name: 'publish-revision', methods: ['POST'], requirements: ['revisionUuid' => '[0-9a-fA-F-]{36}'])]
    public function publishRevisionAction(string $revisionUuid): Response
    {
        $store = $this->authorizeStore('update');
        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['storeUuid' => $store->getUuid()]);

        if (!$setting instanceof StoreWechatSetting) {
            throw new NotFoundHttpException('Store WeChat setting not found.');
        }

        $revision = $this->entityManager
            ->getRepository(StoreWechatSettingRevision::class)
            ->findOneBy(['uuid' => $revisionUuid, 'setting' => $setting]);

        if (!$revision instanceof StoreWechatSettingRevision) {
            throw new NotFoundHttpException('Binding revision not found.');
        }

        $this->bindingService->publish($revision);

        return $this->success($this->serializeRevision($revision));
    }

    private function authorizeStore(string $action): Store
    {
        $scopeId = $this->getRequestStack()->getCurrentRequest()?->attributes->get('scopeId');
        if (!is_string($scopeId) || $scopeId === '') {
            throw new AccessDeniedException('Store scope is required.');
        }

        $store = $this->storeService->get(['uuid' => $scopeId], false);
        if (!$store instanceof Store) {
            throw new AccessDeniedException('Store not found or access denied.');
        }

        $this->denyAccessUnlessGranted('store:wechat:'.$action, $store);

        return $store;
    }

    /** @return array<string, mixed> */
    private function serializeDetail(StoreWechatSetting $setting): array
    {
        $revisions = $this->entityManager
            ->getRepository(StoreWechatSettingRevision::class)
            ->findBy(['setting' => $setting], ['revision' => 'DESC']);
        $published = $this->bindingService->publishedRevision($setting);

        return [
            'uuid' => $setting->getUuid(),
            'storeUuid' => $setting->getStoreUuid(),
            'enabled' => $setting->isEnabled(),
            'published' => $published === null ? null : $this->serializeRevision($published),
            'revisions' => array_map($this->serializeRevision(...), $revisions),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRevision(StoreWechatSettingRevision $revision): array
    {
        return [
            'uuid' => $revision->getUuid(),
            'revision' => $revision->getRevision(),
            'state' => $revision->getState(),
            'capabilities' => $revision->getCapabilities(),
            'miniappRevisionUuid' => $revision->getMiniappRevision()?->getUuid(),
            'officialRevisionUuid' => $revision->getOfficialRevision()?->getUuid(),
            'merchantRevisionUuid' => $revision->getMerchantRevision()?->getUuid(),
            'createdAt' => $revision->getCreatedAt()->format(\DATE_ATOM),
            'publishedAt' => $revision->getPublishedAt()?->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WechatValidationException('Request body must be valid JSON.');
        }

        if (!is_array($data)) {
            throw new WechatValidationException('Request body must be a JSON object.');
        }

        return $data;
    }
}
