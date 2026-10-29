<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Command;

use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\OrderPrintRequestWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:printer:dispatch-orders', description: 'Dispatch queued Trade order print requests.')]
final class DispatchOrderPrintRequestsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrinterJobService $jobs,
        private readonly OrderPrintRequestWriter $claims,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            throw new \LogicException('Order dispatch must run outside a transaction so claims and jobs are durable before provider calls.');
        }
        // Retention cleanup is best-effort: a failure must not stop dispatching.
        try {
            $this->claims->pruneClaims();
        } catch (\Throwable) {
        }
        $requests = $this->entityManager->getRepository(PrinterOrderRequest::class)->findBy(
            ['status' => PrinterOrderRequest::STATUS_PENDING],
            ['createdAt' => 'ASC'],
            50,
        );
        $processed = 0;
        $failed = 0;
        foreach ($requests as $request) {
            if (!$request instanceof PrinterOrderRequest) {
                continue;
            }
            // A claim is never automatically reclaimed: a crashed worker may have sent the job.
            if ($connection->executeStatement(
                'UPDATE printer_order_request SET status = ? WHERE id = ? AND status = ?',
                [PrinterOrderRequest::STATUS_DISPATCHING, $request->getId(), PrinterOrderRequest::STATUS_PENDING],
            ) !== 1) {
                continue;
            }
            $this->entityManager->refresh($request);
            try {
                $configuration = $request->getConfiguration();
                $device = $this->entityManager->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $configuration['deviceUuid'] ?? '']);
                $snapshot = $configuration['templateSnapshot'] ?? null;
                $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $request->getStoreUuid()]);
                if (!$device instanceof PrinterDevice || !$store instanceof Store) {
                    throw new \DomainException('The configured store or printer device no longer exists.');
                }
                if (!is_array($snapshot)) {
                    throw new \DomainException('The queued request has no template snapshot; its template, device or account was unavailable when queued.');
                }
                if (!$device->isEnabled() || !$device->getAccount()->isEnabled()) {
                    throw new \DomainException('The configured printer device or account is disabled.');
                }
                if ($device->getStoreUuid() !== $request->getStoreUuid()) {
                    throw new \DomainException('The configured printer device does not belong to the order store.');
                }
                $copies = (int) ($configuration['copies'] ?? 1);
                $requestKey = 'ord-'.substr(hash('sha256', $request->getRequestKey()), 0, 40);
                $job = $this->jobs->submitSnapshot($device, $snapshot, $request->getPrintData(), $copies, $requestKey);
                // A submitted/unknown job is terminal for automatic dispatch; only an explicit reprint may send again.
                if (!in_array($job->getStatus(), [PrinterJob::STATUS_SUBMITTED, PrinterJob::STATUS_PRINTED, PrinterJob::STATUS_FAILED, PrinterJob::STATUS_UNKNOWN], true)) {
                    throw new \RuntimeException('Printer job did not reach a dispatch result.');
                }
                $request->markProcessed();
                ++$processed;
            } catch (\Throwable $exception) {
                $request->markFailed($exception->getMessage());
                ++$failed;
                $output->writeln(sprintf('<error>Order print request %s failed; inspect its stored error.</error>', $request->getUuid()));
            }
            $this->entityManager->flush();
        }
        $output->writeln(sprintf('Processed %d order print request(s); %d failed.', $processed, $failed));
        return Command::SUCCESS;
    }
}
