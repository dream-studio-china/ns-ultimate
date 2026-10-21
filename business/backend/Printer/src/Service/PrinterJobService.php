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
        private readonly PrinterCredentialCipher $cipher,
        private readonly XpyunClient $xpyun,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function submit(PrinterDevice $device, PrinterTemplate $template, array $data, int $copies, string $idempotencyKey): PrinterJob
    {
        if (!$template->isEnabled()) { throw new \InvalidArgumentException('Printer template is disabled.'); }
        if ($device->getKind() !== $template->getKind()) { throw new \InvalidArgumentException('Template type must match printer type.'); }
        if ($device->getKind() === 'label') {
            $definition = $template->getDefinition();
            if ($device->getWidthMm() !== null) { $definition['widthMm'] = $device->getWidthMm(); }
            if ($device->getHeightMm() !== null) { $definition['heightMm'] = $device->getHeightMm(); }
        } else {
            $definition = $template->getDefinition();
        }
        $document = $this->renderer->render($definition, $data);
        if (count($document['pages']) * $copies > 500) {
            throw new \InvalidArgumentException('A print job may generate at most 500 physical copies/pages.');
        }
        $account = $device->getAccount();
        $credentials = $this->cipher->decrypt($account->getCredentialsCipher());

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
        return $this->dispatch($job, $account->getDeveloperId(), $credentials['userKey']);
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
        $credentials = $this->cipher->decrypt($account->getCredentialsCipher());
        $idempotencyKey = bin2hex(random_bytes(16));
        $job = new PrinterJob(
            $device,
            $source->getCopies(),
            $source->getTemplateSnapshot(),
            $source->getDataSnapshot(),
            $source->getRenderedContent(),
            $idempotencyKey,
        );
        return $this->dispatch($job, $account->getDeveloperId(), $credentials['userKey']);
    }

    /** @return PrinterJob */
    private function dispatch(PrinterJob $job, string $developerId, string $userKey): PrinterJob
    {
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        try {
            $providerKey = substr(hash('sha256', $job->getIdempotencyKey()), 0, 20);
            $response = $this->xpyun->print($developerId, $userKey, $job->getKind(), $job->getDevice()->getSerialNumber(), $job->getRenderedContent(), $job->getCopies(), $providerKey);
            $orderId = $response['data'] ?? null;
            if (!is_string($orderId) || $orderId === '') {
                $job->unknown();
            } else {
                $job->submitted($orderId);
            }
        } catch (XpyunApiException $exception) {
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
        $credentials = $this->cipher->decrypt($account->getCredentialsCipher());
        $response = $this->xpyun->orderStatus($account->getDeveloperId(), $credentials['userKey'], $orderId);
        if (($response['data'] ?? null) === true) { $job->markPrinted(); }
        $this->entityManager->flush();
        return $job;
    }
}
