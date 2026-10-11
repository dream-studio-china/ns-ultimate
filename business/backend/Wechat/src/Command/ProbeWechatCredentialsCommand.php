<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Command;

use NsUltimate\Business\Wechat\Service\WechatCredentialProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Offline readiness probe for stored WeChat credentials.
 *
 * Performs decryption/PEM parsing only and never contacts WeChat, so it cannot
 * create a charge or refund. Each run is audited.
 */
#[AsCommand(name: 'app:wechat:probe-credentials', description: 'Probe a stored WeChat credential revision without contacting WeChat')]
final class ProbeWechatCredentialsCommand extends Command
{
    public function __construct(private readonly WechatCredentialProbe $probe)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'application or merchant')
            ->addArgument('uuid', InputArgument::REQUIRED, 'Revision UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getArgument('type');
        $uuid = (string) $input->getArgument('uuid');

        try {
            $result = match ($type) {
                'application' => $this->probe->probeApplication($uuid),
                'merchant' => $this->probe->probeMerchant($uuid),
                default => throw new \InvalidArgumentException('type must be application or merchant.'),
            };
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        foreach ($result['checks'] as $check => $passed) {
            $output->writeln(sprintf('  %s: %s', $check, $passed ? '<info>ok</info>' : '<error>missing</error>'));
        }
        foreach ($result['errors'] as $error) {
            $output->writeln('  <error>'.$error.'</error>');
        }

        $output->writeln($result['ready'] ? '<info>ready</info>' : '<error>not ready</error>');

        return $result['ready'] ? Command::SUCCESS : Command::FAILURE;
    }
}
