<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;

final class PrinterJobService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintDocumentRenderer $renderer,
        private readonly PrinterProviderRegistry $providers,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function submit(PrinterDevice $device, PrinterTemplate $template, array $data, int $copies, string $idempotencyKey): PrinterJob
    {
        if (!$template->isEnabled()) { throw new \InvalidArgumentException('Printer template is disabled.'); }
        if ($device->getKind() !== $template->getKind()) { throw new \InvalidArgumentException('Template type must match printer type.'); }
        $definition = $template->getDefinition();
        if ($device->getKind() === 'label') {
            if ($device->getWidthMm() !== null) { $definition['widthMm'] = $device->getWidthMm(); }
            if ($device->getHeightMm() !== null) { $definition['heightMm'] = $device->getHeightMm(); }
        }
        return $this->submitSnapshot($device, $definition, $data, $copies, $idempotencyKey);
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $data */
    public function submitSnapshot(PrinterDevice $device, array $definition, array $data, int $copies, string $idempotencyKey): PrinterJob
    {
        if (($definition['kind'] ?? null) !== $device->getKind() || !is_array($definition['nodes'] ?? null)) {
            throw new \InvalidArgumentException('Template snapshot must match the printer type and contain nodes.');
        }
        $document = $this->renderer->render($definition, $data);
        if (count($document['pages']) * $copies > 500) {
            throw new \InvalidArgumentException('A print job may generate at most 500 physical copies/pages.');
        }
        $existing = $this->entityManager->getRepository(PrinterJob::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
        if ($existing instanceof PrinterJob) {
            if ($existing->getDevice()->getUuid() !== $device->getUuid()
                || $existing->getCopies() !== $copies
                || $existing->getRenderedContent() !== $document['content']) {
                throw new \DomainException('Idempotency key was already used with different print content.');
            }
            return $existing;
        }

        $job = new PrinterJob($device, $copies, $definition, $data, $document['content'], $idempotencyKey);
        return $this->dispatch($job);
    }

    /** Create a deliberate, separately identifiable reprint from an immutable job snapshot. */
    public function reprint(PrinterJob $source): PrinterJob
    {
        if (in_array($source->getStatus(), [PrinterJob::STATUS_SUBMITTING, PrinterJob::STATUS_UNKNOWN], true)) {
            throw new \DomainException('This job has an unresolved outcome and cannot be safely reprinted.');
        }
        $device = $source->getDevice();
        $account = $device->getAccount();
        if (!$device->isEnabled() || !$account->isEnabled()) {
            throw new \DomainException('Enable the source printer device and account before reprinting.');
        }
        $idempotencyKey = bin2hex(random_bytes(16));
        $job = new PrinterJob(
            $device,
            $source->getCopies(),
            $source->getTemplateSnapshot(),
            $source->getDataSnapshot(),
            $source->getRenderedContent(),
            $idempotencyKey,
        );
        return $this->dispatch($job);
    }

    /** @return PrinterJob */
    private function dispatch(PrinterJob $job): PrinterJob
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Printer jobs must be dispatched outside a transaction so the job is durable before the provider call.');
        }
        $provider = $this->providers->get($job->getDevice()->getAccount()->getProvider());
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        try {
            $response = $provider->submitJob($job);
            $orderId = $response['providerOrderId'];
            if (!is_string($orderId) || $orderId === '') {
                $job->unknown();
            } else {
                $job->submitted($orderId);
            }
        } catch (PrinterProviderException $exception) {
            $job->failed($exception->providerCode);
        } catch (\Throwable) {
            // A transport failure may occur after the provider accepted the job; never auto-submit again.
            $job->unknown();
        }
        $this->entityManager->flush();

        return $job;
    }

    public function refresh(PrinterJob $job): PrinterJob
    {
        if ($job->getStatus() === PrinterJob::STATUS_PRINTED || $job->getStatus() === PrinterJob::STATUS_FAILED) {
            return $job;
        }
        $orderId = $job->getProviderOrderId();
        if ($orderId === null) {
            throw new \DomainException('This job has no provider order ID; it cannot be safely retried or queried.');
        }
        $account = $job->getDevice()->getAccount();
        $this->providers->get($account->getProvider());
        $response = $this->providers->get($account->getProvider())->queryJobStatus($job);
        if ($response['printed']) { $job->markPrinted(); }
        $this->entityManager->flush();
        return $job;
    }
}
