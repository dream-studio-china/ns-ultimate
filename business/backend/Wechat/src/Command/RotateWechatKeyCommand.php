<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Command;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Service\SecretCipher;
use NsUltimate\Business\Wechat\Service\WechatAuditLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-encrypts stored WeChat credentials under a new master key.
 *
 * Decrypts every credential payload with the current key and encrypts it with
 * the replacement key, so decryption keeps working for required history after
 * rotation. Supports --dry-run to report without writing.
 */
#[AsCommand(name: 'app:wechat:rotate-key', description: 'Re-encrypt stored WeChat credentials under a new master key')]
final class RotateWechatKeyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretCipher $currentCipher,
        private readonly WechatAuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('new-key', null, InputOption::VALUE_REQUIRED, 'Base64-encoded 32-byte replacement master key')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without writing changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $encoded = trim((string) $input->getOption('new-key'));
        if ($encoded === '') {
            $output->writeln('<error>--new-key is required.</error>');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $replacement = new SecretCipher($encoded);
            $replacement->keyId();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Invalid replacement key: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        $rotated = 0;
        $failures = [];

        $applicationRevisions = $this->entityManager->getRepository(WechatApplicationRevision::class)->findAll();
        foreach ($applicationRevisions as $revision) {
            $fields = [
                'secret' => [$revision->encryptedSecret(), fn (?string $v) => $revision->setSecretEncrypted($v)],
                'officialToken' => [$revision->encryptedOfficialToken(), fn (?string $v) => $revision->setOfficialTokenEncrypted($v)],
                'officialAesKey' => [$revision->encryptedOfficialAesKey(), fn (?string $v) => $revision->setOfficialAesKeyEncrypted($v)],
            ];
            foreach ($fields as $name => [$payload, $setter]) {
                $result = $this->rotatePayload($payload, $replacement, $dryRun);
                if ($result === 'rotated') {
                    if (!$dryRun) {
                        $setter($this->replacementEncrypt($replacement, $payload));
                        $revision->setKeyId($replacement->keyId());
                    }
                    $rotated++;
                } elseif ($result === 'failure') {
                    $failures[] = sprintf('application %s: %s', $revision->getUuid(), $name);
                }
            }
        }

        $merchantRevisions = $this->entityManager->getRepository(WechatMerchantRevision::class)->findAll();
        foreach ($merchantRevisions as $revision) {
            $fields = [
                'apiV3Key' => [$revision->encryptedApiV3Key(), fn (?string $v) => $revision->setApiV3KeyEncrypted($v)],
                'privateKey' => [$revision->encryptedPrivateKey(), fn (?string $v) => $revision->setPrivateKeyEncrypted($v)],
                'certificate' => [$revision->encryptedCertificate(), fn (?string $v) => $revision->setCertificateEncrypted($v)],
                'platformPublicKey' => [$revision->encryptedPlatformPublicKey(), fn (?string $v) => $revision->setPlatformPublicKeyEncrypted($v)],
            ];
            foreach ($fields as $name => [$payload, $setter]) {
                $result = $this->rotatePayload($payload, $replacement, $dryRun);
                if ($result === 'rotated') {
                    if (!$dryRun) {
                        $setter($this->replacementEncrypt($replacement, $payload));
                        $revision->setKeyId($replacement->keyId());
                    }
                    $rotated++;
                } elseif ($result === 'failure') {
                    $failures[] = sprintf('merchant %s: %s', $revision->getUuid(), $name);
                }
            }
        }

        if (!$dryRun && $failures === []) {
            $this->entityManager->flush();
            $this->audit->record('key-rotate', 'WechatCredential', 'all', 'success', null, ['rotated' => $rotated]);
        }

        $output->writeln(sprintf(
            '<info>%s</info>: rotated %d credential payloads, failures %d',
            $dryRun ? 'dry-run' : 'apply',
            $rotated,
            count($failures),
        ));
        foreach ($failures as $failure) {
            $output->writeln('  <error>'.$failure.'</error>');
        }

        return $failures === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return string one of rotated|skipped|failure */
    private function rotatePayload(?string $payload, SecretCipher $replacement, bool $dryRun): string
    {
        if ($payload === null) {
            return 'skipped';
        }

        try {
            $this->currentCipher->decrypt($payload);
        } catch (\RuntimeException) {
            return 'failure';
        }

        if (!$dryRun && str_starts_with($payload, 'v1:'.$replacement->keyId().':')) {
            return 'skipped';
        }

        return 'rotated';
    }

    private function replacementEncrypt(SecretCipher $replacement, ?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return $replacement->encrypt($this->currentCipher->decrypt($payload));
    }
}
