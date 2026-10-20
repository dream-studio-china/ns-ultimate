<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Service\PrinterCredentialCipher;
use NsUltimate\Business\Printer\Service\PrinterJobService;
use NsUltimate\Business\Printer\Service\XpyunApiException;
use NsUltimate\Business\Printer\Service\XpyunClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer', name: 'manage-printer-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterManageController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrinterCredentialCipher $cipher,
        private readonly XpyunClient $xpyun,
        private readonly PrinterJobService $jobs,
        private readonly PrintDocumentRenderer $renderer,
    ) {
    }

    #[Route('/accounts', name: 'accounts-list', methods: ['GET'])]
    public function accounts(): JsonResponse
    {
        $items = array_map(fn (PrinterAccount $account): array => $this->accountJson($account), $this->entityManager->getRepository(PrinterAccount::class)->findBy([], ['id' => 'DESC']));
        return new JsonResponse(['items' => $items]);
    }

    #[Route('/accounts', name: 'accounts-create', methods: ['POST'])]
    public function createAccount(Request $request): JsonResponse
    {
        try {
            $body = $request->toArray();
            $account = new PrinterAccount($this->string($body, 'name'), $this->string($body, 'developerId'), $this->cipher->encrypt($this->string($body, 'userKey')));
            $this->entityManager->persist($account);
            $this->entityManager->flush();
            return new JsonResponse($this->accountJson($account), 201);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to save printer account.'], 400);
        }
    }

    #[Route('/devices', name: 'devices-list', methods: ['GET'])]
    public function devices(): JsonResponse
    {
        $items = array_map(fn (PrinterDevice $device): array => $this->deviceJson($device), $this->entityManager->getRepository(PrinterDevice::class)->findBy([], ['id' => 'DESC']));
        return new JsonResponse(['items' => $items]);
    }

    #[Route('/devices', name: 'devices-create', methods: ['POST'])]
    public function createDevice(Request $request): JsonResponse
    {
        try {
            $body = $request->toArray();
            $account = $this->account($this->string($body, 'accountUuid'));
            if (!$account->isEnabled()) { return new JsonResponse(['error' => 'Printer account is disabled.'], 409); }
            $device = new PrinterDevice(
                $account,
                $this->string($body, 'name'),
                $this->string($body, 'serialNumber'),
                $this->string($body, 'kind'),
                isset($body['storeUuid']) && is_string($body['storeUuid']) && $body['storeUuid'] !== '' ? $body['storeUuid'] : null,
                isset($body['model']) && is_string($body['model']) ? $body['model'] : null,
                $this->optionalInteger($body, 'widthMm'),
                $this->optionalInteger($body, 'heightMm'),
            );
            $credentials = $this->cipher->decrypt($account->getCredentialsCipher());
            $response = $this->xpyun->register($account->getDeveloperId(), $credentials['userKey'], $device->getSerialNumber(), $device->getName());
            $success = $response['data']['success'] ?? [];
            if (!is_array($success) || !in_array($device->getSerialNumber(), $success, true)) {
                return new JsonResponse(['error' => 'Xpyun did not confirm device registration.', 'provider' => $response['data'] ?? null], 422);
            }
            $this->entityManager->persist($device);
            $this->entityManager->flush();
            return new JsonResponse($this->deviceJson($device), 201);
        } catch (XpyunApiException $exception) {
            return new JsonResponse(['error' => 'Xpyun rejected device registration.', 'providerCode' => $exception->providerCode], 422);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to register printer device.'], 502);
        }
    }

    #[Route('/devices/{uuid}/status', name: 'device-status', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function refreshDeviceStatus(string $uuid): JsonResponse
    {
        try {
            $device = $this->device($uuid);
            $account = $device->getAccount();
            $credentials = $this->cipher->decrypt($account->getCredentialsCipher());
            $response = $this->xpyun->printerStatus($account->getDeveloperId(), $credentials['userKey'], $device->getSerialNumber());
            $status = $response['data'] ?? null;
            if (!is_int($status) || !in_array($status, [0, 1, 2], true)) { throw new \RuntimeException('Invalid printer status response.'); }
            $device->updateProviderStatus($status);
            $this->entityManager->flush();
            return new JsonResponse($this->deviceJson($device));
        } catch (XpyunApiException $exception) {
            return new JsonResponse(['error' => 'Xpyun status query failed.', 'providerCode' => $exception->providerCode], 502);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to refresh printer status.'], 502);
        }
    }

    #[Route('/templates', name: 'templates-list', methods: ['GET'])]
    public function templates(): JsonResponse
    {
        $items = array_map(fn (PrinterTemplate $template): array => $this->templateJson($template), $this->entityManager->getRepository(PrinterTemplate::class)->findBy([], ['id' => 'DESC']));
        return new JsonResponse(['items' => $items]);
    }

    #[Route('/templates', name: 'templates-create', methods: ['POST'])]
    public function createTemplate(Request $request): JsonResponse
    {
        try {
            $body = $request->toArray();
            $definition = $body['definition'] ?? null;
            if (!is_array($definition)) { throw new \InvalidArgumentException('definition must be an object.'); }
            $sampleData = $body['sampleData'] ?? null;
            if (!is_array($sampleData)) { throw new \InvalidArgumentException('sampleData must be an object used to validate this template.'); }
            $template = new PrinterTemplate($this->string($body, 'name'), $this->string($body, 'kind'), $definition);
            $this->renderer->render($definition, $sampleData);
            $this->entityManager->persist($template);
            $this->entityManager->flush();
            return new JsonResponse($this->templateJson($template), 201);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Unable to save printer template.'], 400);
        }
    }

    #[Route('/jobs', name: 'jobs-list', methods: ['GET'])]
    public function jobs(): JsonResponse
    {
        $items = array_map(fn (PrinterJob $job): array => $this->jobJson($job), $this->entityManager->getRepository(PrinterJob::class)->findBy([], ['id' => 'DESC'], 100));
        return new JsonResponse(['items' => $items]);
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

    private function account(string $uuid): PrinterAccount
    {
        $account = $this->entityManager->getRepository(PrinterAccount::class)->findOneBy(['uuid' => $uuid]);
        return $account instanceof PrinterAccount ? $account : throw new \InvalidArgumentException('Printer account not found.');
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

    /** @param array<string, mixed> $body */
    private function optionalInteger(array $body, string $field): ?int
    {
        $value = $body[$field] ?? null;
        if ($value === null) { return null; }
        if (!is_int($value)) { throw new \InvalidArgumentException(sprintf('%s must be an integer.', $field)); }
        return $value;
    }

    /** @return array<string, mixed> */
    private function accountJson(PrinterAccount $account): array
    {
        return ['uuid' => $account->getUuid(), 'name' => $account->getName(), 'developerId' => $account->getDeveloperId(), 'enabled' => $account->isEnabled(), 'createdAt' => $account->getCreatedAt()->format(DATE_ATOM), 'hasCredential' => true];
    }

    /** @return array<string, mixed> */
    private function deviceJson(PrinterDevice $device): array
    {
        return ['uuid' => $device->getUuid(), 'accountUuid' => $device->getAccount()->getUuid(), 'accountName' => $device->getAccount()->getName(), 'storeUuid' => $device->getStoreUuid(), 'name' => $device->getName(), 'serialNumber' => $device->getSerialNumber(), 'kind' => $device->getKind(), 'model' => $device->getModel(), 'widthMm' => $device->getWidthMm(), 'heightMm' => $device->getHeightMm(), 'enabled' => $device->isEnabled(), 'status' => $device->getProviderStatus(), 'statusCheckedAt' => $device->getStatusCheckedAt()?->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function templateJson(PrinterTemplate $template): array
    {
        return ['uuid' => $template->getUuid(), 'name' => $template->getName(), 'kind' => $template->getKind(), 'definition' => $template->getDefinition(), 'enabled' => $template->isEnabled()];
    }

    /** @return array<string, mixed> */
    private function jobJson(PrinterJob $job): array
    {
        return ['uuid' => $job->getUuid(), 'deviceUuid' => $job->getDevice()->getUuid(), 'deviceName' => $job->getDevice()->getName(), 'storeUuid' => $job->getStoreUuid(), 'kind' => $job->getKind(), 'copies' => $job->getCopies(), 'status' => $job->getStatus(), 'providerOrderId' => $job->getProviderOrderId(), 'errorCode' => $job->getErrorCode(), 'createdAt' => $job->getCreatedAt()->format(DATE_ATOM)];
    }
}
