<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Command;

use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\PrinterJobService;
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
            try {
                $configuration = $request->getConfiguration();
                $device = $this->entityManager->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $configuration['deviceUuid'] ?? '']);
                $template = $this->entityManager->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $configuration['templateUuid'] ?? '']);
                $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $request->getStoreUuid()]);
                if (!$device instanceof PrinterDevice || !$template instanceof PrinterTemplate || !$store instanceof Store) {
                    throw new \DomainException('The configured store, printer device, or template no longer exists.');
                }
                if (!$device->isEnabled() || !$device->getAccount()->isEnabled() || !$template->isEnabled()) {
                    throw new \DomainException('The configured printer device, account, or template is disabled.');
                }
                if ($device->getStoreUuid() !== $request->getStoreUuid()) {
                    throw new \DomainException('The configured printer device does not belong to the order store.');
                }
                if ($device->getKind() !== $template->getKind()) {
                    throw new \DomainException('Printer device and template kinds do not match.');
                }
                $copies = (int) ($configuration['copies'] ?? 1);
                $requestKey = 'ord-'.substr($request->getRequestKey(), 0, 40);
                if ($configuration['scene'] === 'per_item') {
                    $requestKey = 'ord-'.substr(hash('sha256', $request->getRequestKey()), 0, 40);
                }
                $job = $this->jobs->submit($device, $template, $request->getPrintData(), $copies, $requestKey);
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
