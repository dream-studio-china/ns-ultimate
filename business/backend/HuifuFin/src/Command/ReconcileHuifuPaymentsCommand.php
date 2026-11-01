<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Command;

use NsUltimate\Business\HuifuFin\Service\Payment\HuifuReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:huifu:reconcile', description: 'Query pending Huifu payments and refunds')]
final class ReconcileHuifuPaymentsCommand extends Command
{
    public function __construct(private readonly HuifuReconciliationService $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records per type', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $failed = 0;
        foreach ($this->reconciliation->duePayments($limit) as $attempt) {
            try {
                $this->reconciliation->queryPayment($attempt);
            } catch (\Throwable $exception) {
                $failed++;
                $output->writeln(sprintf('<error>Payment %s: %s</error>', $attempt->getUuid(), $exception->getMessage()));
            }
        }
        foreach ($this->reconciliation->dueRefunds($limit) as $refund) {
            try {
                $this->reconciliation->queryRefund($refund);
            } catch (\Throwable $exception) {
                $failed++;
                $output->writeln(sprintf('<error>Refund %s: %s</error>', $refund->getUuid(), $exception->getMessage()));
            }
        }
        $output->writeln($failed === 0 ? '<info>Huifu reconciliation completed.</info>' : sprintf('<comment>Huifu reconciliation completed with %d errors.</comment>', $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
