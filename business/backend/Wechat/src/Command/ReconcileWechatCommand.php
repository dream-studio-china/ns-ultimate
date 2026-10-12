<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Command;

use App\Payment\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Entity\WechatRefundAttempt;
use NsUltimate\Business\Wechat\Service\InvoiceStoreContextAdapter;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use NsUltimate\Business\Wechat\Service\WechatPaymentAttemptService;
use NsUltimate\Business\Wechat\Service\WechatPaymentCompletionService;
use NsUltimate\Business\Wechat\Service\WechatPayProviderInterface;
use NsUltimate\Business\Wechat\Service\WechatRefundOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconciles unresolved payment attempts and pending refunds with WeChat.
 *
 * Queries the provider using the pinned attempt configuration and confirms
 * through the same verification path as notifications. Attempts with no provider
 * record are left for operator review, except never-sent `created` attempts
 * older than the grace period, which are closed without charging.
 */
#[AsCommand(name: 'app:wechat:reconcile', description: 'Reconcile unresolved WeChat payment attempts and pending refunds')]
final class ReconcileWechatCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreWechatResolver $resolver,
        private readonly WechatPayProviderInterface $provider,
        private readonly WechatPaymentCompletionService $completion,
        private readonly WechatRefundOrchestrator $refunds,
        private readonly WechatPaymentAttemptService $attempts,
        private readonly InvoiceStoreContextAdapter $storeContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum attempts to process', 50)
            ->addOption('grace-minutes', null, InputOption::VALUE_REQUIRED, 'Age before a never-sent attempt may close', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $graceMinutes = max(1, (int) $input->getOption('grace-minutes'));

        $confirmed = 0;
        $failed = 0;
        $skipped = 0;

        $attempts = $this->entityManager->getRepository(WechatPaymentAttempt::class)->findBy(
            ['status' => WechatPaymentAttempt::UNRESOLVED_STATUSES],
            ['id' => 'ASC'],
            $limit,
        );

        foreach ($attempts as $attempt) {
            $outcome = $this->reconcilePayment($attempt, $graceMinutes);
            match ($outcome) {
                'confirmed' => $confirmed++,
                'failed' => $failed++,
                default => $skipped++,
            };
        }

        $refunds = $this->entityManager->getRepository(WechatRefundAttempt::class)->findBy(
            ['status' => WechatRefundAttempt::PENDING_STATUSES],
            ['id' => 'ASC'],
            $limit,
        );

        $refundConfirmed = 0;
        $refundFailed = 0;
        foreach ($refunds as $refund) {
            $outcome = $this->reconcileRefund($refund);
            match ($outcome) {
                'succeeded' => $refundConfirmed++,
                'failed' => $refundFailed++,
                default => $skipped++,
            };
        }

        $output->writeln(sprintf(
            '<info>reconcile</info>: payments confirmed %d, failed %d; refunds succeeded %d, failed %d; skipped %d',
            $confirmed,
            $failed,
            $refundConfirmed,
            $refundFailed,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function reconcilePayment(WechatPaymentAttempt $attempt, int $graceMinutes): string
    {
        try {
            $config = $this->resolver->resolveForAttempt($attempt);
            $data = $this->provider->queryByOutTradeNo($config, $attempt->getProviderOrderNo());
        } catch (\Throwable) {
            return 'skipped';
        }

        if (($data['out_trade_no'] ?? null) === null) {
            // The provider has no record of this order.
            if ($attempt->getStatus() === WechatPaymentAttempt::STATUS_CREATED
                && $attempt->getCreatedAt() < new \DateTimeImmutable(sprintf('-%d minutes', $graceMinutes))) {
                $attempt->setStatus(WechatPaymentAttempt::STATUS_CLOSED);
                $this->entityManager->flush();

                return 'failed';
            }

            return 'skipped';
        }

        try {
            $this->completion->confirmFromProviderData($attempt, $data, 'reconcile');

            return 'confirmed';
        } catch (\Throwable) {
            return 'skipped';
        }
    }

    private function reconcileRefund(WechatRefundAttempt $refund): string
    {
        $paymentAttempt = $refund->getPaymentAttempt();
        if (!$paymentAttempt instanceof WechatPaymentAttempt) {
            return 'skipped';
        }

        try {
            $config = $this->resolver->resolveForAttempt($paymentAttempt);
            $data = $this->provider->queryRefund($config, $refund->getProviderRefundNo());
        } catch (\Throwable) {
            return 'skipped';
        }

        if (($data['out_refund_no'] ?? null) === null) {
            return 'skipped';
        }

        try {
            return $this->refunds->confirmRefund($refund, $data, 'reconcile');
        } catch (\Throwable) {
            return 'skipped';
        }
    }
}
