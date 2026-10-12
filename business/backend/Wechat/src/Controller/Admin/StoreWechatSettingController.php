<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

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

#[Route('/manage/store-wechat-settings', name: 'wechat-store-settings-')]
#[IsGranted('ROLE_ADMIN')]
final class StoreWechatSettingController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $repository = $this->entityManager->getRepository(StoreWechatSetting::class);
        $query = $repository->createQueryBuilder('setting');
        $storeUuid = $request->query->get('storeUuid');
        if (is_string($storeUuid) && $storeUuid !== '') {
            $query->andWhere('setting.storeUuid = :storeUuid')->setParameter('storeUuid', $storeUuid);
        }
        $filter = $request->query->get('@filter');
        if (is_string($filter) && $filter !== '') {
            if (!preg_match("/^\\(?\\s*entity\\.getStoreUuid\\(\\)\\s+matches\\s+'([^'\\r\\n]*)'\\s*\\)?$/D", $filter, $matches)) {
                throw new BadRequestHttpException('Unsupported store settings filter.');
            }
            $query->andWhere("setting.storeUuid LIKE :storeSearch ESCAPE '!'")
                ->setParameter('storeSearch', '%'.strtr($matches[1], ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
        }
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));
        $total = (int) (clone $query)->select('COUNT(setting.id)')->getQuery()->getSingleScalarResult();
        $rows = $query->orderBy('setting.id', 'DESC')->setMaxResults($limit)->setFirstResult(($page - 1) * $limit)->getQuery()->getResult();
        return $this->json([
            'data' => array_map($this->settingRow(...), $rows),
            'paginator' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => max(1, (int) ceil($total / $limit)),
                'has_previous' => $page > 1,
                'has_next' => $page * $limit < $total,
            ],
        ]);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $setting = ctype_digit($id)
            ? $this->entityManager->getRepository(StoreWechatSetting::class)->find((int) $id)
            : $this->entityManager->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $id]);
        return $setting instanceof StoreWechatSetting
            ? $this->json(['data' => $this->settingRow($setting)])
            : $this->json(['message' => 'Store WeChat setting not found.'], 404);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if (!is_string($data['storeUuid'] ?? null) || trim($data['storeUuid']) === '') {
            throw new BadRequestHttpException('storeUuid is required.');
        }
        return $this->save($data, $data['storeUuid']);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $setting = ctype_digit($id)
            ? $this->entityManager->getRepository(StoreWechatSetting::class)->find((int) $id)
            : $this->entityManager->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $id]);
        $storeUuid = $setting instanceof StoreWechatSetting ? $setting->getStoreUuid() : $id;
        return $this->save($this->body($request), $storeUuid);
    }

    /** @param array<string, mixed> $data */
    private function save(array $data, string $storeUuid): JsonResponse
    {
        if (!$this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]) instanceof Store) {
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
}
