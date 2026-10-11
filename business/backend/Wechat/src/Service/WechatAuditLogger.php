<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatAuditEvent;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Append-only, redacted audit writer for WeChat configuration changes.
 */
final class WechatAuditLogger
{
    /** @var list<string> */
    private const REDACT_KEY_PARTS = [
        'secret', 'password', 'token', 'key', 'session', 'authorization', 'code', 'certificate', 'aes', 'private',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function record(
        string $action,
        string $objectType,
        string $objectUuid,
        string $result = 'success',
        ?string $storeUuid = null,
        array $context = [],
    ): void {
        $event = (new WechatAuditEvent())
            ->setAction($action)
            ->setObjectType($objectType)
            ->setObjectUuid($objectUuid)
            ->setResult($result)
            ->setStoreUuid($storeUuid)
            ->setContext($this->redact($context));

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $event->setActorUserId($user->getId());
        }

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed> */
    private function redact(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);
            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);
                continue;
            }
            foreach (self::REDACT_KEY_PARTS as $part) {
                if (str_contains($name, $part)) {
                    $redacted[$key] = '[redacted]';
                    continue 2;
                }
            }
            $redacted[$key] = $value;
        }

        return $redacted;
    }
}
