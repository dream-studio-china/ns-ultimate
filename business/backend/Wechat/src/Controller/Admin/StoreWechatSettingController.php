<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\StoreWechatSettingRevision;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;
use NsUltimate\Business\Wechat\Service\StoreWechatBindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administrator management of per-Store WeChat bindings.
 *
 * A binding pins published application/merchant revisions; it never copies
 * credential material.
 */
#[Route('/manage/store-wechat-settings', name: 'business-store-wechat-settings-')]
#[IsGranted('ROLE_ADMIN')]
final class StoreWechatSettingController extends RestController
{
    private const UUID_PATTERN = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreWechatBindingService $bindingService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $settings = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findBy([], ['id' => 'DESC']);

        return $this->success(array_map($this->serialize(...), $settings));
    }

    #[Route('/{uuid}', name: 'detail', methods: ['GET'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function detailAction(string $uuid): Response
    {
        return $this->success($this->serializeDetail($this->requireSetting($uuid)));
    }

    #[Route('/store/{storeUuid}', name: 'by-store', methods: ['GET'], requirements: ['storeUuid' => self::UUID_PATTERN])]
    public function byStoreAction(string $storeUuid): Response
    {
        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['storeUuid' => $storeUuid]);

        if (!$setting instanceof StoreWechatSetting) {
            throw new NotFoundHttpException('Store WeChat setting not found.');
        }

        return $this->success($this->serializeDetail($setting));
    }

    #[Route('/store/{storeUuid}', name: 'update', methods: ['PUT'], requirements: ['storeUuid' => self::UUID_PATTERN])]
    public function updateAction(Request $request, string $storeUuid): Response
    {
        $setting = $this->bindingService->settingForStore($storeUuid);
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

    #[Route('/store/{storeUuid}/revisions', name: 'create-revision', methods: ['POST'], requirements: ['storeUuid' => self::UUID_PATTERN])]
    public function createRevisionAction(Request $request, string $storeUuid): Response
    {
        $setting = $this->bindingService->settingForStore($storeUuid);
        $revision = $this->bindingService->createRevision($setting, $this->jsonBody($request));

        return $this->success($this->serializeRevision($revision), 'SUCCESS', 201);
    }

    #[Route('/store/{storeUuid}/revisions/{revisionUuid}/publish', name: 'publish-revision', methods: ['POST'], requirements: ['storeUuid' => self::UUID_PATTERN, 'revisionUuid' => self::UUID_PATTERN])]
    public function publishRevisionAction(string $storeUuid, string $revisionUuid): Response
    {
        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['storeUuid' => $storeUuid]);

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

    private function requireSetting(string $uuid): StoreWechatSetting
    {
        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['uuid' => $uuid]);

        if (!$setting instanceof StoreWechatSetting) {
            throw new NotFoundHttpException('Store WeChat setting not found.');
        }

        return $setting;
    }

    /** @return array<string, mixed> */
    private function serialize(StoreWechatSetting $setting): array
    {
        return [
            'id' => $setting->getId(),
            'uuid' => $setting->getUuid(),
            'storeUuid' => $setting->getStoreUuid(),
            'enabled' => $setting->isEnabled(),
            'createdAt' => $setting->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $setting->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(StoreWechatSetting $setting): array
    {
        $revisions = $this->entityManager
            ->getRepository(StoreWechatSettingRevision::class)
            ->findBy(['setting' => $setting], ['revision' => 'DESC']);

        $published = $this->bindingService->publishedRevision($setting);

        return $this->serialize($setting) + [
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
