<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;
use NsUltimate\Business\Wechat\Service\WechatMerchantService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administrator management of WeChat Pay merchant profiles and their revisions.
 *
 * Responses are hand-built so encrypted credential columns are never serialized.
 */
#[Route('/manage/wechat-merchants', name: 'business-wechat-merchants-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatMerchantController extends RestController
{
    private const UUID_PATTERN = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatMerchantService $merchantService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $merchants = $this->entityManager
            ->getRepository(WechatMerchant::class)
            ->findBy([], ['id' => 'DESC']);

        return $this->success(array_map($this->serialize(...), $merchants));
    }

    #[Route('/{uuid}', name: 'detail', methods: ['GET'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function detailAction(string $uuid): Response
    {
        return $this->success($this->serializeDetail($this->requireMerchant($uuid)));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $data = $this->jsonBody($request);
        $name = $this->requireString($data, 'name');
        $merchantId = $this->requireString($data, 'merchantId');

        if ($this->entityManager->getRepository(WechatMerchant::class)->findOneBy(['merchantId' => $merchantId]) !== null) {
            throw new WechatValidationException('A WeChat merchant with this merchant ID already exists.');
        }

        $merchant = new WechatMerchant($name, $merchantId);
        $this->entityManager->persist($merchant);
        $this->entityManager->flush();

        return $this->success($this->serialize($merchant), 'SUCCESS', 201);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function updateAction(Request $request, string $uuid): Response
    {
        $merchant = $this->requireMerchant($uuid);
        $data = $this->jsonBody($request);

        if (array_key_exists('name', $data)) {
            $merchant->setName($this->requireString($data, 'name'));
        }
        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                throw new WechatValidationException('enabled must be a boolean.');
            }
            $merchant->setEnabled($data['enabled']);
        }

        $this->entityManager->flush();

        return $this->success($this->serializeDetail($merchant));
    }

    #[Route('/{uuid}/revisions', name: 'list-revisions', methods: ['GET'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function listRevisionsAction(string $uuid): Response
    {
        $merchant = $this->requireMerchant($uuid);
        $revisions = $this->entityManager
            ->getRepository(WechatMerchantRevision::class)
            ->findBy(['merchant' => $merchant], ['revision' => 'DESC']);

        return $this->success(array_map($this->serializeRevision(...), $revisions));
    }

    #[Route('/{uuid}/revisions', name: 'create-revision', methods: ['POST'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function createRevisionAction(Request $request, string $uuid): Response
    {
        $merchant = $this->requireMerchant($uuid);
        $revision = $this->merchantService->createRevision($merchant, $this->jsonBody($request));

        return $this->success($this->serializeRevision($revision), 'SUCCESS', 201);
    }

    #[Route('/{uuid}/revisions/{revisionUuid}/publish', name: 'publish-revision', methods: ['POST'], requirements: ['uuid' => self::UUID_PATTERN, 'revisionUuid' => self::UUID_PATTERN])]
    public function publishRevisionAction(string $uuid, string $revisionUuid): Response
    {
        $merchant = $this->requireMerchant($uuid);
        $revision = $this->entityManager
            ->getRepository(WechatMerchantRevision::class)
            ->findOneBy(['uuid' => $revisionUuid, 'merchant' => $merchant]);

        if (!$revision instanceof WechatMerchantRevision) {
            throw new NotFoundHttpException('Merchant revision not found.');
        }

        $this->merchantService->publish($revision);

        return $this->success($this->serializeRevision($revision));
    }

    private function requireMerchant(string $uuid): WechatMerchant
    {
        $merchant = $this->entityManager
            ->getRepository(WechatMerchant::class)
            ->findOneBy(['uuid' => $uuid]);

        if (!$merchant instanceof WechatMerchant) {
            throw new NotFoundHttpException('WeChat merchant not found.');
        }

        return $merchant;
    }

    /** @return array<string, mixed> */
    private function serialize(WechatMerchant $merchant): array
    {
        return [
            'id' => $merchant->getId(),
            'uuid' => $merchant->getUuid(),
            'name' => $merchant->getName(),
            'merchantId' => $merchant->getMerchantId(),
            'mode' => $merchant->getMode(),
            'enabled' => $merchant->isEnabled(),
            'createdAt' => $merchant->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $merchant->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(WechatMerchant $merchant): array
    {
        $revisions = $this->entityManager
            ->getRepository(WechatMerchantRevision::class)
            ->findBy(['merchant' => $merchant], ['revision' => 'DESC']);

        return $this->serialize($merchant) + [
            'revisions' => array_map($this->serializeRevision(...), $revisions),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRevision(WechatMerchantRevision $revision): array
    {
        return [
            'uuid' => $revision->getUuid(),
            'revision' => $revision->getRevision(),
            'mode' => $revision->getMode(),
            'state' => $revision->getState(),
            'certificateSerial' => $revision->getCertificateSerial(),
            'platformPublicKeyId' => $revision->getPlatformPublicKeyId(),
            'hasApiV3Key' => $revision->hasApiV3Key(),
            'hasPrivateKey' => $revision->hasPrivateKey(),
            'hasCertificate' => $revision->hasCertificate(),
            'hasPlatformPublicKey' => $revision->hasPlatformPublicKey(),
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

    /** @param array<string, mixed> $data */
    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new WechatValidationException(sprintf('%s is required and must be a non-empty string.', $key));
        }

        return trim($value);
    }
}
