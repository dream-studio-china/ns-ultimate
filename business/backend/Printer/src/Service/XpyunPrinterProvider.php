<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;

final class XpyunPrinterProvider implements PrinterProviderInterface
{
    public function __construct(
        private readonly PrinterCredentialCipher $cipher,
        private readonly XpyunClient $client,
    ) {
    }

    public function code(): string { return PrinterAccount::PROVIDER_XPYUN; }

    public function registerDevice(PrinterAccount $account, PrinterDevice $device): array
    {
        $credentials = $this->credentials($account);
        $response = $this->client->register($account->getDeveloperId(), $credentials['userKey'], $device->getSerialNumber(), $device->getName());
        $success = $response['data']['success'] ?? [];
        if (!is_array($success) || !in_array($device->getSerialNumber(), $success, true)) {
            throw new PrinterProviderException('registration_unconfirmed', 'Xpyun did not confirm device registration.');
        }
        return ['registeredSerialNumbers' => array_values(array_filter($success, 'is_string'))];
    }

    public function queryDeviceStatus(PrinterDevice $device): array
    {
        $account = $device->getAccount();
        $credentials = $this->credentials($account);
        $response = $this->client->printerStatus($account->getDeveloperId(), $credentials['userKey'], $device->getSerialNumber());
        $status = $response['data'] ?? null;
        if (!is_int($status) || !in_array($status, [0, 1, 2], true)) {
            throw new PrinterProviderException('invalid_device_status', 'Provider returned an invalid device status.');
        }
        return ['status' => $status];
    }

    public function submitJob(PrinterJob $job): array
    {
        $account = $job->getDevice()->getAccount();
        $credentials = $this->credentials($account);
        $providerKey = substr(hash('sha256', $job->getIdempotencyKey()), 0, 20);
        $response = $this->client->print($account->getDeveloperId(), $credentials['userKey'], $job->getKind(), $job->getDevice()->getSerialNumber(), $job->getRenderedContent(), $job->getCopies(), $providerKey);
        $orderId = $response['data'] ?? null;
        return ['providerOrderId' => is_string($orderId) && $orderId !== '' ? $orderId : null];
    }

    public function queryJobStatus(PrinterJob $job): array
    {
        $account = $job->getDevice()->getAccount();
        $credentials = $this->credentials($account);
        $orderId = $job->getProviderOrderId();
        if ($orderId === null) { throw new \DomainException('This job has no provider order ID.'); }
        $response = $this->client->orderStatus($account->getDeveloperId(), $credentials['userKey'], $orderId);
        return ['printed' => ($response['data'] ?? null) === true];
    }

    /** @return array{userKey: string} */
    private function credentials(PrinterAccount $account): array
    {
        $credentials = $this->cipher->decrypt($account->getCredentialsCipher());
        if (!is_string($credentials['userKey'] ?? null) || $credentials['userKey'] === '') {
            throw new \LogicException('Xpyun account credentials are incomplete.');
        }
        return ['userKey' => $credentials['userKey']];
    }
}
