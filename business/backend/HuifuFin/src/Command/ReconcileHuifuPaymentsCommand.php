<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Command;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:huifu:reconcile', description: 'Query pending Huifu payments and refunds')]
final class ReconcileHuifuPaymentsCommand extends Command
{
    public function __construct(
        private readonly HuifuReconciliationService $reconciliation,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records per type', '50');
        $this->addOption('refund', null, InputOption::VALUE_REQUIRED, 'Reconcile only this existing Huifu refund UUID, ignoring its next query time');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refundUuid = $input->getOption('refund');
        if ($refundUuid !== null) {
            if (!is_string($refundUuid) || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $refundUuid)) {
                $output->writeln('<error>A valid Huifu refund UUID is required.</error>');
                return Command::INVALID;
            }
            $refund = $this->entityManager->getRepository(HuifuRefund::class)->findOneBy(['uuid' => strtolower($refundUuid)]);
            if (!$refund instanceof HuifuRefund || in_array($refund->getStatus(), [HuifuRefund::STATUS_CREATED, HuifuRefund::STATUS_FAILED, HuifuRefund::STATUS_CANCELLED], true)) {
                $output->writeln('<error>No eligible submitted Huifu refund was found.</error>');
                return Command::INVALID;
            }
            try {
                $this->reconciliation->queryRefund($refund);
            } catch (\Throwable $exception) {
                $output->writeln(sprintf('<error>Refund %s: %s</error>', $refund->getUuid(), $exception->getMessage()));
                return Command::FAILURE;
            }
            $output->writeln(sprintf('Refund %s: status=%s, coreApplied=%s', $refund->getUuid(), $refund->getStatus(), $refund->isCoreApplied() ? 'yes' : 'no'));
            return $refund->isCoreApplied() ? Command::SUCCESS : Command::FAILURE;
        }
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
