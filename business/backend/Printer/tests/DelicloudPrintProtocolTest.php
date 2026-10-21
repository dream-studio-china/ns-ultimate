<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Service\DelicloudPrintProtocol;
use PHPUnit\Framework\TestCase;

final class DelicloudPrintProtocolTest extends TestCase
{
    public function testBuildsV19DeviceInfoQueryAndTaskNotification(): void
    {
        $protocol = new DelicloudPrintProtocol();
        self::assertSame([
            'mid' => 'm-1', 'from' => 'app-1', 'to' => 'device-1', 'time' => 123,
            'action' => 301, 'data' => ['cmd' => 'server_push_report_info'],
        ], $protocol->queryDeviceInfo('m-1', 'app-1', 'device-1', 123));
        self::assertSame([
            'mid' => 'm-2', 'from' => 'app-1', 'to' => 'device-1', 'time' => 124,
            'action' => 301, 'data' => ['cmd' => 'server_push_task_add', 'payload' => ['task_type' => 'print']],
        ], $protocol->notifyPrintTask('m-2', 'app-1', 'device-1', 124));
    }

    public function testBuildsV2PullRequestAndAcknowledgesInContext(): void
    {
        $protocol = new DelicloudPrintProtocol();
        self::assertSame('printer_push_task_execute_v2', $protocol->requestTaskExecutionV2('m', 'device', 'app', 10)['data']['cmd']);
        $ack = $protocol->acknowledge(['mid' => 'in-1', 'from' => 'device', 'data' => ['cmd' => 'printer_push_report_info']], 'app');
        self::assertSame(['mid' => 'in-1', 'from' => 'app', 'to' => 'device', 'action' => 301, 'data' => ['cmd' => 'printer_push_report_info']], array_diff_key($ack, ['time' => true]));
    }

    public function testParsesDeviceInformationAndPrintProgress(): void
    {
        $protocol = new DelicloudPrintProtocol();
        $info = $protocol->parseDeviceInfoReport(['action' => 300, 'from' => 'deli-device', 'data' => ['cmd' => 'printer_push_report_info', 'payload' => [
            'printer_name' => 'Deli printer', 'work_status' => 'idle', 'firmware_version' => '1.9', 'network_mode' => 'WLAN',
        ]]]);
        self::assertSame('idle', $info['payload']['work_status']);

        $progress = $protocol->parsePrintProgress(['action' => 300, 'from' => 'deli-device', 'data' => ['cmd' => 'printer_push_print_progress', 'payload' => [
            'task_id' => 'task-1', 'print_status' => 'finish', 'printed_page_count' => 2, 'printed_paper_count' => 2,
        ]]]);
        self::assertSame(['deli-device', 'task-1', 'finish'], [$progress['deviceId'], $progress['taskId'], $progress['status']]);
    }

    public function testRejectsMalformedOrWrongProtocolMessages(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DelicloudPrintProtocol())->parsePrintProgress(['action' => 300, 'from' => 'd', 'data' => ['cmd' => 'printer_push_print_progress', 'payload' => ['task_id' => 't', 'print_status' => 'done']]]);
    }
}
