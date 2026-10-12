<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Command;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Service\WechatAuditLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hard-revokes a compromised credential revision.
 *
 * Revocation blocks every use of the revision, including historical payment and
 * refund processing. It is distinct from disabling: disabling stops new
 * operations but leaves historical callbacks and reconciliation working.
 */
#[AsCommand(name: 'app:wechat:revoke', description: 'Hard-revoke a compromised WeChat credential revision')]
final class RevokeWechatCredentialCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatAuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'application or merchant')
            ->addArgument('uuid', InputArgument::REQUIRED, 'Revision UUID')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Revocation reason for the audit log', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getArgument('type');
        $uuid = (string) $input->getArgument('uuid');

        $revision = match ($type) {
            'application' => $this->entityManager->getRepository(WechatApplicationRevision::class)->findOneBy(['uuid' => $uuid]),
            'merchant' => $this->entityManager->getRepository(WechatMerchantRevision::class)->findOneBy(['uuid' => $uuid]),
            default => null,
        };

        if (!$revision instanceof WechatApplicationRevision && !$revision instanceof WechatMerchantRevision) {
            $output->writeln('<error>Unknown revision. Type must be application or merchant.</error>');
            return Command::FAILURE;
        }

        if ($revision->isRevoked()) {
            $output->writeln('<comment>Already revoked.</comment>');
            return Command::SUCCESS;
        }

        $revision->revoke();
        $this->entityManager->flush();
        $this->audit->record('revoke', $revision instanceof WechatApplicationRevision ? 'WechatApplicationRevision' : 'WechatMerchantRevision', $revision->getUuid(), 'success', null, [
            'reason' => (string) $input->getOption('reason'),
        ]);

        $output->writeln(sprintf('<info>Revoked %s revision %s.</info>', $type, $uuid));

        return Command::SUCCESS;
    }
}
