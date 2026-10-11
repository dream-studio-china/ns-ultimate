<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;

/**
 * Offline credential readiness probe.
 *
 * Verifies that stored credentials decrypt and parse; it never contacts WeChat,
 * so it cannot create a charge or refund and is safe to run repeatedly.
 */
final class WechatCredentialProbe
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretCipher $cipher,
        private readonly WechatAuditLogger $audit,
    ) {
    }

    /** @return array{ready: bool, checks: array<string, bool>, errors: list<string>} */
    public function probeApplication(string $revisionUuid): array
    {
        $revision = $this->entityManager->getRepository(WechatApplicationRevision::class)->findOneBy(['uuid' => $revisionUuid]);
        if (!$revision instanceof WechatApplicationRevision) {
            throw new \InvalidArgumentException('Application revision was not found.');
        }

        $application = $revision->getApplication();
        $checks = [
            'secret' => false,
            'officialToken' => $application?->getType() !== 'official',
            'officialAesKey' => $application?->getType() !== 'official',
        ];
        $errors = [];

        try {
            $checks['secret'] = $revision->encryptedSecret() !== null && $this->cipher->decrypt($revision->encryptedSecret()) !== '';
        } catch (\RuntimeException $e) {
            $errors[] = 'secret: '.$e->getMessage();
        }

        if ($application?->getType() === 'official') {
            try {
                $checks['officialToken'] = $revision->encryptedOfficialToken() !== null
                    && $this->cipher->decrypt($revision->encryptedOfficialToken()) !== '';
            } catch (\RuntimeException $e) {
                $errors[] = 'officialToken: '.$e->getMessage();
            }
            try {
                $key = $revision->encryptedOfficialAesKey() === null ? '' : $this->cipher->decrypt($revision->encryptedOfficialAesKey());
                $checks['officialAesKey'] = strlen($key) === 43;
            } catch (\RuntimeException $e) {
                $errors[] = 'officialAesKey: '.$e->getMessage();
            }
        }

        $ready = !in_array(false, $checks, true);
        $this->audit->record('probe', 'WechatApplicationRevision', $revision->getUuid(), $ready ? 'success' : 'failure', null, ['checks' => $checks]);

        return ['ready' => $ready, 'checks' => $checks, 'errors' => $errors];
    }

    /** @return array{ready: bool, checks: array<string, bool>, errors: list<string>} */
    public function probeMerchant(string $revisionUuid): array
    {
        $revision = $this->entityManager->getRepository(WechatMerchantRevision::class)->findOneBy(['uuid' => $revisionUuid]);
        if (!$revision instanceof WechatMerchantRevision) {
            throw new \InvalidArgumentException('Merchant revision was not found.');
        }

        $checks = ['apiV3Key' => false, 'privateKey' => false, 'certificate' => false];
        $errors = [];

        try {
            $key = $revision->encryptedApiV3Key() === null ? '' : $this->cipher->decrypt($revision->encryptedApiV3Key());
            $checks['apiV3Key'] = strlen($key) === 32;
        } catch (\RuntimeException $e) {
            $errors[] = 'apiV3Key: '.$e->getMessage();
        }

        try {
            $privateKey = $revision->encryptedPrivateKey() === null ? '' : $this->cipher->decrypt($revision->encryptedPrivateKey());
            $checks['privateKey'] = $privateKey !== '' && openssl_pkey_get_private($privateKey) !== false;
        } catch (\RuntimeException $e) {
            $errors[] = 'privateKey: '.$e->getMessage();
        }

        try {
            $certificate = $revision->encryptedCertificate() === null ? '' : $this->cipher->decrypt($revision->encryptedCertificate());
            $checks['certificate'] = $certificate !== '' && openssl_x509_read($certificate) !== false;
        } catch (\RuntimeException $e) {
            $errors[] = 'certificate: '.$e->getMessage();
        }

        $ready = !in_array(false, $checks, true);
        $this->audit->record('probe', 'WechatMerchantRevision', $revision->getUuid(), $ready ? 'success' : 'failure', null, ['checks' => $checks]);

        return ['ready' => $ready, 'checks' => $checks, 'errors' => $errors];
    }
}
