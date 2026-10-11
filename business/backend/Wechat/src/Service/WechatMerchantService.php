<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;

/**
 * Creates and publishes immutable WeChat merchant revisions.
 *
 * Only ordinary merchant direct mode is supported in the first release.
 */
final class WechatMerchantService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretCipher $cipher,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createRevision(WechatMerchant $merchant, array $input): WechatMerchantRevision
    {
        [$apiV3Key, $privateKey, $certificate, $platformPublicKeyId, $platformPublicKey] = $this->validate($input);

        $revision = new WechatMerchantRevision($merchant, $this->nextRevisionNumber($merchant));
        $revision->setMode(WechatMerchant::MODE_DIRECT);
        $revision->setEncryptedCredentials(
            $this->cipher->keyId(),
            $this->cipher->encrypt($apiV3Key),
            $this->cipher->encrypt($privateKey),
            $this->cipher->encrypt($certificate),
            $platformPublicKeyId,
            $platformPublicKey === null ? null : $this->cipher->encrypt($platformPublicKey),
        );

        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        return $revision;
    }

    public function publish(WechatMerchantRevision $revision): void
    {
        if ($revision->getState() !== WechatMerchantRevision::STATE_DRAFT) {
            throw new WechatValidationException('Only draft merchant revisions can be published.');
        }

        $merchant = $revision->getMerchant();
        $current = $this->entityManager
            ->getRepository(WechatMerchantRevision::class)
            ->findOneBy(['merchant' => $merchant, 'state' => WechatMerchantRevision::STATE_PUBLISHED]);

        if ($current instanceof WechatMerchantRevision) {
            $current->supersede();
        }

        $revision->publish();
        $this->entityManager->flush();
    }

    public function disable(WechatMerchant $merchant): void
    {
        $merchant->setEnabled(false);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string, 2: string, 3: ?string, 4: ?string}
     */
    private function validate(array $input): array
    {
        $mode = $input['mode'] ?? WechatMerchant::MODE_DIRECT;
        if (!is_string($mode) || $mode !== WechatMerchant::MODE_DIRECT) {
            throw new WechatValidationException('Only ordinary merchant direct mode is supported.');
        }

        $apiV3Key = $input['apiV3Key'] ?? null;
        if (!is_string($apiV3Key) || strlen(trim($apiV3Key)) !== 32) {
            throw new WechatValidationException('The WeChat Pay API v3 key must be exactly 32 characters.');
        }
        $apiV3Key = trim($apiV3Key);

        $privateKey = $input['privateKey'] ?? null;
        if (!is_string($privateKey) || !str_contains($privateKey, 'BEGIN') || !str_contains($privateKey, 'PRIVATE KEY')) {
            throw new WechatValidationException('A valid PEM merchant private key is required.');
        }

        $certificate = $input['certificate'] ?? null;
        if (!is_string($certificate) || !str_contains($certificate, 'BEGIN CERTIFICATE')) {
            throw new WechatValidationException('A valid PEM merchant certificate is required.');
        }

        $platformPublicKeyId = $input['platformPublicKeyId'] ?? null;
        $platformPublicKey = $input['platformPublicKey'] ?? null;
        if (($platformPublicKeyId === null) !== ($platformPublicKey === null)) {
            throw new WechatValidationException('platformPublicKeyId and platformPublicKey must be provided together.');
        }
        if ($platformPublicKeyId !== null) {
            if (!is_string($platformPublicKeyId) || trim($platformPublicKeyId) === '') {
                throw new WechatValidationException('platformPublicKeyId must be a non-empty string.');
            }
            if (!is_string($platformPublicKey) || !str_contains($platformPublicKey, 'PUBLIC KEY')) {
                throw new WechatValidationException('A valid PEM platform public key is required.');
            }
            $platformPublicKeyId = trim($platformPublicKeyId);
        }

        return [$apiV3Key, $privateKey, $certificate, $platformPublicKeyId, $platformPublicKey];
    }

    private function nextRevisionNumber(WechatMerchant $merchant): int
    {
        $max = $this->entityManager->createQuery(
            'SELECT MAX(r.revision) FROM '.WechatMerchantRevision::class.' r WHERE r.merchant = :merchant',
        )->setParameter('merchant', $merchant)->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
