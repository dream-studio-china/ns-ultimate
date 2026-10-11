<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;

/**
 * Creates and publishes immutable WeChat application revisions.
 *
 * A profile is edited by creating a new draft revision; publishing supersedes the
 * previously published revision for the same profile. Store bindings keep
 * referencing the exact revision they were published with.
 */
final class WechatApplicationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretCipher $cipher,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createRevision(WechatApplication $application, array $input): WechatApplicationRevision
    {
        [$secret, $officialToken, $officialAesKey, $redirectHosts] = $this->validate($application, $input);

        $revision = new WechatApplicationRevision($application, $this->nextRevisionNumber($application));
        $revision->setRedirectHosts($redirectHosts);
        $revision->setEncryptedCredentials(
            $this->cipher->keyId(),
            $this->cipher->encrypt($secret),
            $officialToken === null ? null : $this->cipher->encrypt($officialToken),
            $officialAesKey === null ? null : $this->cipher->encrypt($officialAesKey),
        );

        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        return $revision;
    }

    public function publish(WechatApplicationRevision $revision): void
    {
        if ($revision->getState() !== WechatApplicationRevision::STATE_DRAFT) {
            throw new WechatValidationException('Only draft application revisions can be published.');
        }

        $application = $revision->getApplication();
        $current = $this->entityManager
            ->getRepository(WechatApplicationRevision::class)
            ->findOneBy(['application' => $application, 'state' => WechatApplicationRevision::STATE_PUBLISHED]);

        if ($current instanceof WechatApplicationRevision) {
            $current->supersede();
        }

        $revision->publish();
        $this->entityManager->flush();
    }

    public function disable(WechatApplication $application): void
    {
        $application->setEnabled(false);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: ?string, 2: ?string, 3: list<string>}
     */
    private function validate(WechatApplication $application, array $input): array
    {
        $secret = $input['secret'] ?? null;
        if (!is_string($secret) || trim($secret) === '') {
            throw new WechatValidationException('A WeChat application revision requires a non-empty secret.');
        }
        $secret = trim($secret);

        $officialToken = null;
        $officialAesKey = null;
        if ($application->getType() === WechatApplication::TYPE_OFFICIAL) {
            $officialToken = $input['officialToken'] ?? null;
            if (!is_string($officialToken) || trim($officialToken) === '') {
                throw new WechatValidationException('An Official Account revision requires a non-empty token.');
            }
            $officialToken = trim($officialToken);

            $officialAesKey = $input['officialAesKey'] ?? null;
            if (!is_string($officialAesKey) || strlen(trim($officialAesKey)) !== 43) {
                throw new WechatValidationException('An Official Account revision requires a 43-character AES key.');
            }
            $officialAesKey = trim($officialAesKey);
        }

        $hosts = $input['redirectHosts'] ?? [];
        if (!is_array($hosts)) {
            throw new WechatValidationException('redirectHosts must be a list of host names.');
        }
        $validatedHosts = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', trim($host)) !== 1) {
                throw new WechatValidationException(sprintf('Invalid redirect host "%s".', is_string($host) ? $host : gettype($host)));
            }
            $validatedHosts[] = strtolower(trim($host));
        }

        return [$secret, $officialToken, $officialAesKey, $validatedHosts];
    }

    private function nextRevisionNumber(WechatApplication $application): int
    {
        $max = $this->entityManager->createQuery(
            'SELECT MAX(r.revision) FROM '.WechatApplicationRevision::class.' r WHERE r.application = :application',
        )->setParameter('application', $application)->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
