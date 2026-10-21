<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

/**
 * Builds and validates DeliCloud IoT print-protocol messages (V1.9).
 * This is a protocol codec only; it does not provide MQTT delivery or task conversion/upload.
 */
final class DelicloudPrintProtocol
{
    public const ACTION_DEVICE_TO_APP = 300;
    public const ACTION_APP_TO_DEVICE = 301;

    /**
     * Request that the device reports its current information.
     * @return array<string, mixed>
     */
    public function queryDeviceInfo(string $messageId, string $applicationId, string $deviceId, ?int $time = null): array
    {
        return $this->message($messageId, $applicationId, $deviceId, self::ACTION_APP_TO_DEVICE, 'server_push_report_info', null, $time);
    }

    /**
     * Notify a device that a converted task is available for it to pull.
     * @return array<string, mixed>
     */
    public function notifyPrintTask(string $messageId, string $applicationId, string $deviceId, ?int $time = null): array
    {
        return $this->message($messageId, $applicationId, $deviceId, self::ACTION_APP_TO_DEVICE, 'server_push_task_add', ['task_type' => 'print'], $time);
    }

    /**
     * Ask the device to pull pending work using the required HTTPS V2 protocol.
     * @return array<string, mixed>
     */
    public function requestTaskExecutionV2(string $messageId, string $deviceId, string $applicationId, ?int $time = null): array
    {
        return $this->message($messageId, $deviceId, $applicationId, self::ACTION_DEVICE_TO_APP, 'printer_push_task_execute_v2', null, $time);
    }

    /**
     * Acknowledge a received device message in the same message context.
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function acknowledge(array $message, string $applicationId): array
    {
        $messageId = $message['mid'] ?? null;
        $deviceId = $message['from'] ?? null;
        $command = $message['data']['cmd'] ?? null;
        if (!is_string($messageId) || $messageId === '' || !is_string($deviceId) || $deviceId === '' || !is_string($command) || $command === '') {
            throw new \InvalidArgumentException('DeliCloud message must include mid, from and data.cmd.');
        }

        return [
            'mid' => $messageId,
            'from' => $applicationId,
            'to' => $deviceId,
            'time' => time(),
            'action' => self::ACTION_APP_TO_DEVICE,
            'data' => ['cmd' => $command],
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @return array{deviceId: string, payload: array<string, mixed>}
     */
    public function parseDeviceInfoReport(array $message): array
    {
        $this->assertCommand($message, 'printer_push_report_info', self::ACTION_DEVICE_TO_APP);
        $deviceId = $message['from'] ?? null;
        $payload = $message['data']['payload'] ?? null;
        if (!is_string($deviceId) || $deviceId === '' || !is_array($payload)) {
            throw new \InvalidArgumentException('DeliCloud device information report is malformed.');
        }
        foreach (['printer_name', 'work_status', 'firmware_version', 'network_mode'] as $required) {
            if (!is_string($payload[$required] ?? null) || $payload[$required] === '') {
                throw new \InvalidArgumentException(sprintf('DeliCloud report is missing %s.', $required));
            }
        }
        if (!in_array($payload['work_status'], ['idle', 'busy', 'error'], true)) {
            throw new \InvalidArgumentException('Unknown DeliCloud printer work_status.');
        }

        return ['deviceId' => $deviceId, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $message
     * @return array{deviceId: string, taskId: string, status: string, payload: array<string, mixed>}
     */
    public function parsePrintProgress(array $message): array
    {
        $this->assertCommand($message, 'printer_push_print_progress', self::ACTION_DEVICE_TO_APP);
        $deviceId = $message['from'] ?? null;
        $payload = $message['data']['payload'] ?? null;
        if (!is_string($deviceId) || $deviceId === '' || !is_array($payload)
            || !is_string($payload['task_id'] ?? null) || $payload['task_id'] === ''
            || !is_string($payload['print_status'] ?? null)
            || !in_array($payload['print_status'], ['queue', 'printing', 'pause', 'finish', 'fail', 'cancel'], true)) {
            throw new \InvalidArgumentException('DeliCloud print progress message is malformed.');
        }
        foreach (['printed_page_count', 'printed_paper_count'] as $count) {
            if (!is_int($payload[$count] ?? null) || $payload[$count] < 0) {
                throw new \InvalidArgumentException(sprintf('DeliCloud print progress requires a non-negative %s.', $count));
            }
        }

        return ['deviceId' => $deviceId, 'taskId' => $payload['task_id'], 'status' => $payload['print_status'], 'payload' => $payload];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function message(string $messageId, string $from, string $to, int $action, string $command, ?array $payload, ?int $time): array
    {
        foreach (['mid' => $messageId, 'from' => $from, 'to' => $to] as $field => $value) {
            if (trim($value) === '') { throw new \InvalidArgumentException(sprintf('%s cannot be empty.', $field)); }
        }
        $data = ['cmd' => $command];
        if ($payload !== null) { $data['payload'] = $payload; }

        return ['mid' => $messageId, 'from' => $from, 'to' => $to, 'time' => $time ?? time(), 'action' => $action, 'data' => $data];
    }

    /** @param array<string, mixed> $message */
    private function assertCommand(array $message, string $command, int $action): void
    {
        if (($message['action'] ?? null) !== $action || ($message['data']['cmd'] ?? null) !== $command) {
            throw new \InvalidArgumentException(sprintf('Expected DeliCloud command %s with action %d.', $command, $action));
        }
    }
}
