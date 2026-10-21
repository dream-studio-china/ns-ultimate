<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\PrinterProviderException;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer', name: 'manage-printer-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterManageController extends RestController
{
    use ApiView;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrinterProviderRegistry $providers,
        private readonly PrinterJobService $jobs,
    ) {
    }

    #[Route('/devices/{uuid}/status', name: 'device-status', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function refreshDeviceStatus(string $uuid): JsonResponse
    {
        try {
            $device = $this->device($uuid);
            $account = $device->getAccount();
            if (!$device->isEnabled() || !$account->isEnabled()) { return new JsonResponse(['error' => 'Printer device or account is disabled.'], 409); }
            $response = $this->providers->get($account->getProvider())->queryDeviceStatus($device);
            $device->updateProviderStatus($response['status']);
            $this->entityManager->flush();
            return new JsonResponse($this->deviceJson($device));
        } catch (PrinterProviderException $exception) {
            return new JsonResponse(['error' => 'Printer provider status query failed.', 'providerCode' => $exception->providerCode], 502);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to refresh printer status.'], 502);
        }
    }

    #[Route('/jobs', name: 'jobs-create', methods: ['POST'])]
    public function createJob(Request $request): JsonResponse
    {
        try {
            $body = $request->toArray();
            if (!is_array($body['data'] ?? null)) { throw new \InvalidArgumentException('data must be an object.'); }
            $device = $this->device($this->string($body, 'deviceUuid'));
            $template = $this->template($this->string($body, 'templateUuid'));
            if (!$device->isEnabled() || !$device->getAccount()->isEnabled()) { return new JsonResponse(['error' => 'Printer device or account is disabled.'], 409); }
            if ($device->getKind() !== $template->getKind()) { throw new \InvalidArgumentException('Template type must match printer type.'); }
            $copies = $body['copies'] ?? 1;
            if (!is_int($copies)) { throw new \InvalidArgumentException('copies must be an integer.'); }
            $job = $this->jobs->submit($device, $template, $body['data'], $copies, $this->string($body, 'idempotencyKey'));
            return new JsonResponse($this->jobJson($job), $job->getStatus() === PrinterJob::STATUS_FAILED ? 422 : 201);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to create print job.'], 502);
        }
    }

    #[Route('/jobs/{uuid}/status', name: 'job-status', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function refreshJobStatus(string $uuid): JsonResponse
    {
        try {
            $job = $this->entityManager->getRepository(PrinterJob::class)->findOneBy(['uuid' => $uuid]);
            if (!$job instanceof PrinterJob) { return new JsonResponse(['error' => 'Print job not found.'], 404); }
            $job = $this->jobs->refresh($job);
            return new JsonResponse($this->jobJson($job));
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to refresh print job status.'], 502);
        }
    }

    #[Route('/jobs/{uuid}/reprint', name: 'job-reprint', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function reprintJob(string $uuid): JsonResponse
    {
        try {
            $source = $this->entityManager->getRepository(PrinterJob::class)->findOneBy(['uuid' => $uuid]);
            if (!$source instanceof PrinterJob) { return new JsonResponse(['error' => 'Print job not found.'], 404); }
            $job = $this->jobs->reprint($source);
            return new JsonResponse($this->jobJson($job), $job->getStatus() === PrinterJob::STATUS_FAILED ? 422 : 201);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to reprint job.'], 502);
        }
    }

    private function device(string $uuid): PrinterDevice
    {
        $device = $this->entityManager->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $uuid]);
        return $device instanceof PrinterDevice ? $device : throw new \InvalidArgumentException('Printer device not found.');
    }

    private function template(string $uuid): PrinterTemplate
    {
        $template = $this->entityManager->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $uuid]);
        return $template instanceof PrinterTemplate ? $template : throw new \InvalidArgumentException('Printer template not found.');
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || trim($value) === '') { throw new \InvalidArgumentException(sprintf('%s is required.', $field)); }
        return trim($value);
    }

    /** @return array<string, mixed> */
    private function deviceJson(PrinterDevice $device): array
    {
        return ['uuid' => $device->getUuid(), 'accountUuid' => $device->getAccount()->getUuid(), 'accountName' => $device->getAccount()->getName(), 'storeUuid' => $device->getStoreUuid(), 'name' => $device->getName(), 'serialNumber' => $device->getSerialNumber(), 'kind' => $device->getKind(), 'model' => $device->getModel(), 'widthMm' => $device->getWidthMm(), 'heightMm' => $device->getHeightMm(), 'enabled' => $device->isEnabled(), 'status' => $device->getProviderStatus(), 'statusCheckedAt' => $device->getStatusCheckedAt()?->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function jobJson(PrinterJob $job): array
    {
        return ['uuid' => $job->getUuid(), 'deviceUuid' => $job->getDevice()->getUuid(), 'deviceName' => $job->getDevice()->getName(), 'storeUuid' => $job->getStoreUuid(), 'kind' => $job->getKind(), 'copies' => $job->getCopies(), 'status' => $job->getStatus(), 'providerOrderId' => $job->getProviderOrderId(), 'errorCode' => $job->getErrorCode(), 'createdAt' => $job->getCreatedAt()->format(DATE_ATOM)];
    }
}
