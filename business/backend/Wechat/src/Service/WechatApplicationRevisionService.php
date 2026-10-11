<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @extends BaseService<WechatApplicationRevision>
 */
final class WechatApplicationRevisionService extends BaseService
{
    public function __construct(
        ContainerInterface $container,
        private readonly SecretCipher $cipher,
    ) {
        parent::__construct($container, WechatApplicationRevision::class);
    }

    public function nextRevision(WechatApplication $application): int
    {
        $max = $this->em->createQuery(
            'SELECT MAX(r.revision) FROM '.WechatApplicationRevision::class.' r WHERE r.application = :application',
        )->setParameter('application', $application)->getSingleScalarResult();

        return ((int) $max) + 1;
    }

    /**
     * Validate and encrypt the credential input for a draft revision.
     *
     * @param array<string, mixed> $input
     */
    public function applyCredentials(WechatApplicationRevision $revision, array $input): void
    {
        $application = $revision->getApplication();
        if (!$application instanceof WechatApplication) {
            throw new \InvalidArgumentException('An application revision requires an application.');
        }

        $secret = $input['secret'] ?? null;
        if (!is_string($secret) || trim($secret) === '') {
            throw new \InvalidArgumentException('A WeChat application revision requires a non-empty secret.');
        }

        $officialToken = null;
        $officialAesKey = null;
        if ($application->getType() === WechatApplication::TYPE_OFFICIAL) {
            $officialToken = $input['officialToken'] ?? null;
            if (!is_string($officialToken) || trim($officialToken) === '') {
                throw new \InvalidArgumentException('An Official Account revision requires a non-empty token.');
            }
            $officialAesKey = $input['officialAesKey'] ?? null;
            if (!is_string($officialAesKey) || strlen(trim($officialAesKey)) !== 43) {
                throw new \InvalidArgumentException('An Official Account revision requires a 43-character AES key.');
            }
            $officialToken = trim($officialToken);
            $officialAesKey = trim($officialAesKey);
        }

        $hosts = $input['redirectHosts'] ?? [];
        if (!is_array($hosts)) {
            throw new \InvalidArgumentException('redirectHosts must be a list of host names.');
        }
        $validatedHosts = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', trim($host)) !== 1) {
                throw new \InvalidArgumentException('Invalid redirect host.');
            }
            $validatedHosts[] = strtolower(trim($host));
        }

        $revision->setRedirectHosts($validatedHosts);
        $revision->setKeyId($this->cipher->keyId());
        $revision->setSecretEncrypted($this->cipher->encrypt(trim($secret)));
        $revision->setOfficialTokenEncrypted($officialToken === null ? null : $this->cipher->encrypt($officialToken));
        $revision->setOfficialAesKeyEncrypted($officialAesKey === null ? null : $this->cipher->encrypt($officialAesKey));
    }

    public function publish(WechatApplicationRevision $revision): void
    {
        if ($revision->getState() !== WechatApplicationRevision::STATE_DRAFT) {
            throw new \InvalidArgumentException('Only draft application revisions can be published.');
        }
        $this->validateForPublication($revision);
        $current = $this->rep->findOneBy([
            'application' => $revision->getApplication(),
            'state' => WechatApplicationRevision::STATE_PUBLISHED,
        ]);
        if ($current instanceof WechatApplicationRevision && $current !== $revision) {
            $current->supersede();
        }

        $revision->publish();
        $this->em->flush();
    }

    public function isBound(WechatApplicationRevision $revision): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(setting.id)')
            ->from(StoreWechatSetting::class, 'setting')
            ->where('setting.miniappRevision = :revision OR setting.officialRevision = :revision')
            ->setParameter('revision', $revision)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    public function validateForPublication(WechatApplicationRevision $revision): void
    {
        $application = $revision->getApplication();
        if (!$application instanceof WechatApplication) {
            throw new \InvalidArgumentException('An application revision requires an application.');
        }
        if (!$revision->isSecretConfigured()) {
            throw new \InvalidArgumentException('An application revision requires a secret before publication.');
        }
        if ($application->getType() === WechatApplication::TYPE_OFFICIAL
            && (!$revision->isOfficialTokenConfigured() || !$revision->isOfficialAesKeyConfigured())) {
            throw new \InvalidArgumentException('An Official Account revision requires a token and AES key before publication.');
        }
    }
}
