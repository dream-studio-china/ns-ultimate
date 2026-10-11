<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;
use NsUltimate\Business\Wechat\Service\WechatApplicationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administrator management of WeChat application profiles and their revisions.
 *
 * Responses are hand-built so encrypted credential columns are never serialized.
 */
#[Route('/manage/wechat-applications', name: 'business-wechat-applications-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatApplicationController extends RestController
{
    private const UUID_PATTERN = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatApplicationService $applicationService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $applications = $this->entityManager
            ->getRepository(WechatApplication::class)
            ->findBy([], ['id' => 'DESC']);

        return $this->success(array_map($this->serialize(...), $applications));
    }

    #[Route('/{uuid}', name: 'detail', methods: ['GET'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function detailAction(string $uuid): Response
    {
        return $this->success($this->serializeDetail($this->requireApplication($uuid)));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $data = $this->jsonBody($request);
        $name = $this->requireString($data, 'name');
        $type = $this->requireString($data, 'type');
        $appId = $this->requireString($data, 'appId');

        if ($this->entityManager->getRepository(WechatApplication::class)->findOneBy(['appId' => $appId]) !== null) {
            throw new WechatValidationException('A WeChat application with this AppID already exists.');
        }

        $application = new WechatApplication($name, $type, $appId);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $this->success($this->serialize($application), 'SUCCESS', 201);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function updateAction(Request $request, string $uuid): Response
    {
        $application = $this->requireApplication($uuid);
        $data = $this->jsonBody($request);

        if (array_key_exists('name', $data)) {
            $application->setName($this->requireString($data, 'name'));
        }
        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                throw new WechatValidationException('enabled must be a boolean.');
            }
            $application->setEnabled($data['enabled']);
        }

        $this->entityManager->flush();

        return $this->success($this->serializeDetail($application));
    }

    #[Route('/{uuid}/revisions', name: 'list-revisions', methods: ['GET'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function listRevisionsAction(string $uuid): Response
    {
        $application = $this->requireApplication($uuid);
        $revisions = $this->entityManager
            ->getRepository(WechatApplicationRevision::class)
            ->findBy(['application' => $application], ['revision' => 'DESC']);

        return $this->success(array_map($this->serializeRevision(...), $revisions));
    }

    #[Route('/{uuid}/revisions', name: 'create-revision', methods: ['POST'], requirements: ['uuid' => self::UUID_PATTERN])]
    public function createRevisionAction(Request $request, string $uuid): Response
    {
        $application = $this->requireApplication($uuid);
        $revision = $this->applicationService->createRevision($application, $this->jsonBody($request));

        return $this->success($this->serializeRevision($revision), 'SUCCESS', 201);
    }

    #[Route('/{uuid}/revisions/{revisionUuid}/publish', name: 'publish-revision', methods: ['POST'], requirements: ['uuid' => self::UUID_PATTERN, 'revisionUuid' => self::UUID_PATTERN])]
    public function publishRevisionAction(string $uuid, string $revisionUuid): Response
    {
        $application = $this->requireApplication($uuid);
        $revision = $this->entityManager
            ->getRepository(WechatApplicationRevision::class)
            ->findOneBy(['uuid' => $revisionUuid, 'application' => $application]);

        if (!$revision instanceof WechatApplicationRevision) {
            throw new NotFoundHttpException('Application revision not found.');
        }

        $this->applicationService->publish($revision);

        return $this->success($this->serializeRevision($revision));
    }

    private function requireApplication(string $uuid): WechatApplication
    {
        $application = $this->entityManager
            ->getRepository(WechatApplication::class)
            ->findOneBy(['uuid' => $uuid]);

        if (!$application instanceof WechatApplication) {
            throw new NotFoundHttpException('WeChat application not found.');
        }

        return $application;
    }

    /** @return array<string, mixed> */
    private function serialize(WechatApplication $application): array
    {
        return [
            'id' => $application->getId(),
            'uuid' => $application->getUuid(),
            'name' => $application->getName(),
            'type' => $application->getType(),
            'appId' => $application->getAppId(),
            'enabled' => $application->isEnabled(),
            'createdAt' => $application->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $application->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(WechatApplication $application): array
    {
        $revisions = $this->entityManager
            ->getRepository(WechatApplicationRevision::class)
            ->findBy(['application' => $application], ['revision' => 'DESC']);

        return $this->serialize($application) + [
            'revisions' => array_map($this->serializeRevision(...), $revisions),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRevision(WechatApplicationRevision $revision): array
    {
        return [
            'uuid' => $revision->getUuid(),
            'revision' => $revision->getRevision(),
            'state' => $revision->getState(),
            'redirectHosts' => $revision->getRedirectHosts(),
            'hasSecret' => $revision->hasSecret(),
            'hasOfficialToken' => $revision->hasOfficialToken(),
            'hasOfficialAesKey' => $revision->hasOfficialAesKey(),
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
