<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatOAuthState;

/**
 * Issues and single-use consumes opaque Official Account OAuth state.
 *
 * The browser token is an unguessable nonce; the authoritative Store,
 * application revision, approved redirect host, and expiry live in the row.
 */
final class WechatOAuthStateService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function issue(string $storeUuid, WechatApplicationRevision $revision, string $redirectHost, int $ttlSeconds = 300): string
    {
        $nonce = bin2hex(random_bytes(32));
        $state = (new WechatOAuthState())
            ->setNonce($nonce)
            ->setStoreUuid($storeUuid)
            ->setApplicationRevision($revision)
            ->setRedirectHost($redirectHost)
            ->setExpiresAt(new \DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds)));

        $this->entityManager->persist($state);
        $this->entityManager->flush();

        return $nonce;
    }

    public function consume(string $storeUuid, string $nonce): WechatOAuthState
    {
        $state = $this->entityManager->getRepository(WechatOAuthState::class)->findOneBy(['nonce' => $nonce]);
        if (!$state instanceof WechatOAuthState) {
            throw new \InvalidArgumentException('Invalid OAuth state.');
        }
        if ($state->getStoreUuid() !== $storeUuid) {
            throw new \InvalidArgumentException('OAuth state does not belong to this store.');
        }
        if ($state->isExpired()) {
            throw new \InvalidArgumentException('OAuth state has expired.');
        }

        // Atomic single-use consumption: only one concurrent callback can win.
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE wechat_oauth_state SET consumed_at = :now WHERE nonce = :nonce AND consumed_at IS NULL',
            [
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'nonce' => $nonce,
            ],
        );
        if ($affected !== 1) {
            throw new \InvalidArgumentException('OAuth state has already been used.');
        }

        $this->entityManager->refresh($state);

        return $state;
    }
}
