<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use Doctrine\ORM\OptimisticLockException;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @extends BaseService<WechatMerchantRevision>
 */
final class WechatMerchantRevisionService extends BaseService
{
    public function __construct(
        ContainerInterface $container,
        private readonly SecretCipher $cipher,
        private readonly WechatAuditLogger $audit,
    ) {
        parent::__construct($container, WechatMerchantRevision::class);
    }

    public function nextRevision(WechatMerchant $merchant): int
    {
        $max = $this->em->createQuery(
            'SELECT MAX(r.revision) FROM '.WechatMerchantRevision::class.' r WHERE r.merchant = :merchant',
        )->setParameter('merchant', $merchant)->getSingleScalarResult();

        return ((int) $max) + 1;
    }

    /**
     * Validate and encrypt the credential input for a draft revision.
     *
     * @param array<string, mixed> $input
     */
    public function applyCredentials(WechatMerchantRevision $revision, array $input): void
    {
        if (!$revision->getMerchant() instanceof WechatMerchant) {
            throw new \InvalidArgumentException('A merchant revision requires a merchant.');
        }

        $mode = $input['mode'] ?? $revision->getMerchant()?->getMode() ?? WechatMerchant::MODE_DIRECT;
        if (!is_string($mode) || !in_array($mode, WechatMerchant::MODES, true)) {
            throw new \InvalidArgumentException('Unsupported WeChat merchant mode.');
        }

        $apiV3Key = $input['apiV3Key'] ?? null;
        if (!is_string($apiV3Key) || strlen(trim($apiV3Key)) !== 32) {
            throw new \InvalidArgumentException('The WeChat Pay API v3 key must be exactly 32 characters.');
        }

        $privateKey = $input['privateKey'] ?? null;
        if (!is_string($privateKey) || !str_contains($privateKey, 'BEGIN') || !str_contains($privateKey, 'PRIVATE KEY')) {
            throw new \InvalidArgumentException('A valid PEM merchant private key is required.');
        }

        $certificate = $input['certificate'] ?? null;
        if (!is_string($certificate) || !str_contains($certificate, 'BEGIN CERTIFICATE')) {
            throw new \InvalidArgumentException('A valid PEM merchant certificate is required.');
        }

        $platformPublicKeyId = $input['platformPublicKeyId'] ?? null;
        $platformPublicKey = $input['platformPublicKey'] ?? null;
        if (($platformPublicKeyId === null) !== ($platformPublicKey === null)) {
            throw new \InvalidArgumentException('platformPublicKeyId and platformPublicKey must be provided together.');
        }
        if ($platformPublicKeyId !== null) {
            if (!is_string($platformPublicKeyId) || trim($platformPublicKeyId) === '') {
                throw new \InvalidArgumentException('platformPublicKeyId must be a non-empty string.');
            }
            if (!is_string($platformPublicKey) || !str_contains($platformPublicKey, 'PUBLIC KEY')) {
                throw new \InvalidArgumentException('A valid PEM platform public key is required.');
            }
            $platformPublicKeyId = trim($platformPublicKeyId);
        }

        $revision->setMode($mode);
        $revision->setCertificateSerial(null);
        $revision->setKeyId($this->cipher->keyId());
        $revision->setApiV3KeyEncrypted($this->cipher->encrypt(trim($apiV3Key)));
        $revision->setPrivateKeyEncrypted($this->cipher->encrypt($privateKey));
        $revision->setCertificateEncrypted($this->cipher->encrypt($certificate));
        $revision->setPlatformPublicKeyId($platformPublicKeyId);
        $revision->setPlatformPublicKeyEncrypted($platformPublicKey === null ? null : $this->cipher->encrypt($platformPublicKey));
    }

    public function publish(WechatMerchantRevision $revision): void
    {
        if ($revision->getState() !== WechatMerchantRevision::STATE_DRAFT) {
            throw new \InvalidArgumentException('Only draft merchant revisions can be published.');
        }
        $this->validateForPublication($revision);

        $current = $this->rep->findOneBy([
            'merchant' => $revision->getMerchant(),
            'state' => WechatMerchantRevision::STATE_PUBLISHED,
        ]);
        try {
            if ($current instanceof WechatMerchantRevision && $current !== $revision) {
                $current->supersede();
            }

            $revision->publish();
            $this->em->flush();
        } catch (OptimisticLockException $e) {
            throw new ConflictHttpException('The revision changed concurrently; reload and retry.', $e);
        }

        $this->audit->record('publish', 'WechatMerchantRevision', $revision->getUuid(), 'success', null, [
            'merchant' => $revision->getMerchant()?->getUuid(),
            'revision' => $revision->getRevision(),
        ]);
    }

    public function isBound(WechatMerchantRevision $revision): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(setting.id)')
            ->from(StoreWechatSetting::class, 'setting')
            ->where('setting.merchantRevision = :revision')
            ->setParameter('revision', $revision)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    public function validateForPublication(WechatMerchantRevision $revision): void
    {
        if (!$revision->getMerchant() instanceof WechatMerchant) {
            throw new \InvalidArgumentException('A merchant revision requires a merchant.');
        }
        if (!$revision->isApiV3KeyConfigured() || !$revision->isPrivateKeyConfigured() || !$revision->isCertificateConfigured()) {
            throw new \InvalidArgumentException('A merchant revision requires the API v3 key, private key, and certificate before publication.');
        }
    }
}
