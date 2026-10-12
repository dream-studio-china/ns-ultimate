<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller;

use App\Identity\Entity\User;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WechatConfigurationController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/api/v1/manage/store-wechat-settings', name: 'wechat-store-settings-list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(): JsonResponse
    {
        $rows = array_map($this->settingRow(...), $this->entityManager->getRepository(StoreWechatSetting::class)->findBy([], ['id' => 'DESC']));
        return $this->json(['data' => $rows]);
    }

    #[Route('/api/v1/manage/store-wechat-settings/{id}', name: 'wechat-store-settings-detail', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function detail(string $id): JsonResponse
    {
        $setting = ctype_digit($id)
            ? $this->entityManager->getRepository(StoreWechatSetting::class)->find((int) $id)
            : $this->entityManager->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $id]);
        return $setting instanceof StoreWechatSetting
            ? $this->json(['data' => $this->settingRow($setting)])
            : $this->json(['message' => 'Store WeChat setting not found.'], 404);
    }

    #[Route('/api/v1/manage/store-wechat-settings', name: 'wechat-store-settings-create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if (!is_string($data['storeUuid'] ?? null) || trim($data['storeUuid']) === '') {
            throw new BadRequestHttpException('storeUuid is required.');
        }
        return $this->save($data, $data['storeUuid']);
    }

    #[Route('/api/v1/manage/store-wechat-settings/{id}', name: 'wechat-store-settings-update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Request $request, string $id): JsonResponse
    {
        $setting = ctype_digit($id)
            ? $this->entityManager->getRepository(StoreWechatSetting::class)->find((int) $id)
            : $this->entityManager->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $id]);
        $storeUuid = $setting instanceof StoreWechatSetting ? $setting->getStoreUuid() : $id;
        return $this->save($this->body($request), $storeUuid);
    }

    #[Route('/api/v1/store/{scopeId}/wechat-setting', name: 'wechat-store-setting', methods: ['GET', 'PUT'])]
    #[IsGranted('ROLE_USER')]
    public function store(Request $request, string $scopeId): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->canManageStore($scopeId)) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('PUT')) {
            return $this->save($this->body($request), $scopeId);
        }

        $setting = $this->entityManager->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $scopeId]);
        return $this->json([
            'data' => $setting instanceof StoreWechatSetting ? $this->settingRow($setting) : null,
            'applications' => array_map($this->applicationRow(...), $this->entityManager->getRepository(WechatApplication::class)->findBy([], ['name' => 'ASC'])),
            'merchants' => array_map($this->merchantRow(...), $this->entityManager->getRepository(WechatMerchant::class)->findBy([], ['name' => 'ASC'])),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function save(array $data, string $storeUuid): JsonResponse
    {
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        if (!$store instanceof Store) {
            return $this->json(['message' => 'Store not found.'], 404);
        }
        $miniapp = $this->application($data['miniappUuid'] ?? null, WechatApplication::TYPE_MINIAPP);
        $official = $this->application($data['officialUuid'] ?? null, WechatApplication::TYPE_OFFICIAL);
        $merchant = is_string($data['merchantUuid'] ?? null)
            ? $this->entityManager->getRepository(WechatMerchant::class)->findOneBy(['uuid' => $data['merchantUuid']])
            : null;
        if (!$merchant instanceof WechatMerchant || ($miniapp === null && $official === null)) {
            throw new BadRequestHttpException('Select a merchant and at least one application.');
        }
        $setting = $this->entityManager->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $storeUuid]);
        if (!$setting instanceof StoreWechatSetting) {
            $setting = new StoreWechatSetting($storeUuid, $miniapp, $official, $merchant);
            $this->entityManager->persist($setting);
        } else {
            $setting->setMiniapp($miniapp)->setOfficial($official)->setMerchant($merchant);
        }
        $this->entityManager->flush();
        return $this->json(['data' => $this->settingRow($setting)]);
    }

    private function canManageStore(string $storeUuid): bool
    {
        $user = $this->getUser();
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        if (!$user instanceof User || !$store instanceof Store) {
            return false;
        }
        $membership = $this->entityManager->getRepository(Membership::class)->findOneBy([
            'store' => $store,
            'userUuid' => $user->getUuid(),
            'status' => Membership::STATUS_ACTIVE,
        ]);
        return $membership instanceof Membership && in_array($membership->getRole(), [Membership::ROLE_OWNER, Membership::ROLE_MANAGER], true);
    }

    private function application(mixed $uuid, string $type): ?WechatApplication
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $application = is_string($uuid)
            ? $this->entityManager->getRepository(WechatApplication::class)->findOneBy(['uuid' => $uuid])
            : null;
        if (!$application instanceof WechatApplication || $application->getType() !== $type) {
            throw new BadRequestHttpException('Selected WeChat application is invalid.');
        }
        return $application;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON body.', $exception);
        }
        if (!is_array($body)) {
            throw new BadRequestHttpException('A JSON object is required.');
        }
        return $body;
    }

    /** @return array<string, mixed> */
    private function settingRow(StoreWechatSetting $setting): array
    {
        return [
            'id' => $setting->getId(), 'uuid' => $setting->getUuid(), 'storeUuid' => $setting->getStoreUuid(),
            'miniappUuid' => $setting->getMiniapp()?->getUuid(), 'officialUuid' => $setting->getOfficial()?->getUuid(),
            'merchantUuid' => $setting->getMerchant()->getUuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function applicationRow(WechatApplication $application): array
    {
        return ['id' => $application->getId(), 'uuid' => $application->getUuid(), 'name' => $application->getName(), 'type' => $application->getType(), 'appId' => $application->getAppId()];
    }

    /** @return array<string, mixed> */
    private function merchantRow(WechatMerchant $merchant): array
    {
        return ['id' => $merchant->getId(), 'uuid' => $merchant->getUuid(), 'name' => $merchant->getName(), 'merchantId' => $merchant->getMerchantId()];
    }
}
