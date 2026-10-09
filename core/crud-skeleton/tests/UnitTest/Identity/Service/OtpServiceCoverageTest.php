<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Service;

use App\Identity\Service\OtpService;
use App\Identity\Service\OtpStorageInterface;
use App\Identity\Sms\SmsProviderInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the remaining lines of src/Identity/Service/OtpService.php
 * (uncovered lines 111, 112, 115 in var/uncovered-map.txt). Those lines belong
 * to the private maskPhone() helper, which is only reachable when a PSR logger
 * is injected and a log record is emitted (the pre-existing OtpServiceTest
 * always passes logger=null). maskPhone() is exercised through the public
 * generateAndSend() and verify() entry points by capturing the log context.
 */
#[AllowMockObjectsWithoutExpectations]
final class OtpServiceCoverageTest extends TestCase
{
    private OtpStorageInterface $storage;
    private SmsProviderInterface $sms;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(OtpStorageInterface::class);
        $this->sms = $this->createMock(SmsProviderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeService(): OtpService
    {
        return new OtpService($this->storage, $this->sms, 300, 60, 5, $this->logger);
    }

    public function testGenerateAndSendMasksLongPhoneInLog(): void
    {
        $context = [];
        $this->logger->method('info')->willReturnCallback(
            function (string $message, array $ctx) use (&$context): void {
                $context = $ctx;
            },
        );
        $this->storage->method('exists')->willReturn(false);
        $this->storage->method('setex')->willReturn(true);
        $this->sms->method('sendSms')->willReturn(true);

        $this->makeService()->generateAndSend('+8613912345678', 'login', 'SMS_001');

        // maskPhone('+8613912345678') → mb_substr(0,3) . '****' . mb_substr(-4)
        self::assertSame('+86****5678', $context['phone'] ?? null);
    }

    public function testGenerateAndSendMasksVeryShortPhoneInLog(): void
    {
        $context = [];
        $this->logger->method('info')->willReturnCallback(
            function (string $message, array $ctx) use (&$context): void {
                $context = $ctx;
            },
        );
        $this->storage->method('exists')->willReturn(false);
        $this->storage->method('setex')->willReturn(true);
        $this->sms->method('sendSms')->willReturn(true);

        $this->makeService()->generateAndSend('1234', 'login', 'SMS_001');

        // maskPhone('1234') → length <= 4 → '***'
        self::assertSame('***', $context['phone'] ?? null);
    }

    public function testVerifyMasksPhoneInMaxAttemptsLog(): void
    {
        $context = [];
        $this->logger->method('info')->willReturnCallback(
            function (string $message, array $ctx) use (&$context): void {
                $context = $ctx;
            },
        );

        $hash = $this->makeService()->hashOtp('999999');
        $stored = json_encode(['hash' => $hash, 'tries' => 4], JSON_THROW_ON_ERROR);

        $this->storage->method('get')->with('otp:login:+8613912345678')->willReturn($stored);
        $this->storage->method('del')->willReturn(1);

        $result = $this->makeService()->verify('+8613912345678', 'login', '000000');

        self::assertFalse($result);
        self::assertSame('+86****5678', $context['phone'] ?? null);
    }
}
