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
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $success = is_array($data['success'] ?? null) ? $data['success'] : [];
        if (!in_array($device->getSerialNumber(), $success, true)) {
            $code = $this->registrationFailureCode($device->getSerialNumber(), $data);
            // Xpyun returns 1011 when the serial is already bound to this
            // account. Confirm access with a status query before treating the
            // existing binding as a successful registration.
            if ($code === '1011') {
                try {
                    $this->queryDeviceStatus($device);
                    return ['registeredSerialNumbers' => [$device->getSerialNumber()]];
                } catch (PrinterProviderException) {
                    throw new PrinterProviderException('registration_unconfirmed', 'Xpyun reports the printer already registered, but it is not accessible from this account.');
                }
            }
            throw new PrinterProviderException('registration_unconfirmed', $this->registrationMessage($code));
        }
        return ['registeredSerialNumbers' => array_values(array_filter($success, 'is_string'))];
    }

    /** @param array<string, mixed> $data */
    private function registrationFailureCode(string $serialNumber, array $data): ?string
    {
        foreach (is_array($data['failMsg'] ?? null) ? $data['failMsg'] : [] as $message) {
            if (is_string($message) && preg_match('/^'.preg_quote($serialNumber, '/').':(\d{1,4})$/', $message, $matches) === 1) {
                return $matches[1];
            }
        }
        return null;
    }

    private function registrationMessage(?string $code): string
    {
        $reason = match ($code) {
            '1009' => 'the printer number or name is empty',
            '1010' => 'the printer number is invalid',
            '1011' => 'the printer is already registered',
            '1012' => 'the provider could not add the device',
            null => 'the provider did not confirm registration',
            default => 'the provider rejected registration with code '.$code,
        };
        return sprintf('Xpyun did not confirm device registration: %s.', $reason);
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
        if ($account->getCredentialsCipher() === '') {
            throw new PrinterProviderException('missing_credentials', 'Xpyun account credentials are incomplete.');
        }
        try {
            $credentials = $this->cipher->decrypt($account->getCredentialsCipher());
        } catch (\RuntimeException) {
            throw new PrinterProviderException('invalid_credentials', 'Stored printer credentials cannot be decrypted.');
        }
        if (!is_string($credentials['userKey'] ?? null) || $credentials['userKey'] === '') {
            // A missing credential is a definitive configuration failure, not an
            // ambiguous transport outcome, so the job must be marked failed.
            throw new PrinterProviderException('missing_credentials', 'Xpyun account credentials are incomplete.');
        }
        return ['userKey' => $credentials['userKey']];
    }
}
