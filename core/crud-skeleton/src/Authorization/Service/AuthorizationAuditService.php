<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthorizationAuditService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(?string $actorUuid, string $action, string $targetType, ?string $targetUuid = null, ?array $before = null, ?array $after = null): AuditLog
    {
        $requestId = null;
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $requestId = $request->headers->get('X-Request-Id') ?? $request->headers->get('X-Request-ID');
            if ($requestId !== null) {
                $requestId = substr((string) $requestId, 0, 64);
            }
        }

        $log = new AuditLog($action, $targetType, $targetUuid, $actorUuid);
        $log->setBeforeData($before);
        $log->setAfterData($after);
        $log->setRequestId($requestId);

        $this->em->persist($log);

        return $log;
    }
}
