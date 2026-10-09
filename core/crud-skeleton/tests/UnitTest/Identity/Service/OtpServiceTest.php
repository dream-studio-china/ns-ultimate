<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Service;

use App\Identity\Service\OtpService;
use App\Identity\Service\OtpStorageInterface;
use App\Identity\Sms\SmsProviderInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class OtpServiceTest extends TestCase
{
    private OtpService $otpService;
    private OtpStorageInterface $storage;
    private SmsProviderInterface $sms;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(OtpStorageInterface::class);
        $this->sms = $this->createMock(SmsProviderInterface::class);

        $this->otpService = new OtpService(
            $this->storage,
            $this->sms,
            300,
            60,
            5,
        );
    }

    public function testGenerateAndSendStoresOtpAndSendsSms(): void
    {
        $phone = '+8613912345678';
        $template = 'SMS_001';

        $this->storage->expects(self::once())
            ->method('exists')
            ->with('otp:login:+8613912345678:cooldown')
            ->willReturn(false);

        $this->storage->expects(self::exactly(2))
            ->method('setex')
            ->willReturn(true);

        $this->sms->expects(self::once())
            ->method('sendSms')
            ->with($phone, $template, self::callback(fn (array $p) => isset($p['code']) && \strlen((string) $p['code']) === 6))
            ->willReturn(true);

        $this->otpService->generateAndSend($phone, 'login', $template);

        // No exception means success
        self::assertTrue(true);
    }

    public function testGenerateAndSendThrowsWhenRateLimited(): void
    {
        $this->storage->expects(self::once())
            ->method('exists')
            ->with('otp:login:+8613912345678:cooldown')
            ->willReturn(true);

        $this->sms->expects(self::never())->method('sendSms');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OTP sent too recently');

        $this->otpService->generateAndSend('+8613912345678', 'login', 'SMS_001');
    }

    public function testGenerateAndSendCleansUpOnSmsFailure(): void
    {
        $this->storage->expects(self::once())
            ->method('exists')
            ->with('otp:login:+8613912345678:cooldown')
            ->willReturn(false);

        $this->storage->expects(self::exactly(2))->method('setex')->willReturn(true);
        $this->storage->expects(self::once())->method('del')->with(
            'otp:login:+8613912345678',
            'otp:login:+8613912345678:cooldown',
        );

        $this->sms->expects(self::once())->method('sendSms')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to send SMS');

        $this->otpService->generateAndSend('+8613912345678', 'login', 'SMS_001');
    }

    public function testVerifyCorrectOtpReturnsTrue(): void
    {
        $phone = '+8613912345678';
        $purpose = 'login';
        $otp = '123456';

        $hash = $this->otpService->hashOtp($otp);
        $stored = json_encode(['hash' => $hash, 'tries' => 0]);

        $this->storage->expects(self::once())
            ->method('get')
            ->with('otp:login:+8613912345678')
            ->willReturn($stored);

        $this->storage->expects(self::once())
            ->method('del')
            ->with('otp:login:+8613912345678');

        $result = $this->otpService->verify($phone, $purpose, $otp);

        self::assertTrue($result);
    }

    public function testVerifyIncorrectOtpReturnsFalse(): void
    {
        $phone = '+8613912345678';
        $hash = $this->otpService->hashOtp('999999');
        $stored = json_encode(['hash' => $hash, 'tries' => 0]);

        $this->storage->expects(self::once())
            ->method('get')
            ->with('otp:login:+8613912345678')
            ->willReturn($stored);

        $this->storage->expects(self::once())
            ->method('ttl')
            ->with('otp:login:+8613912345678')
            ->willReturn(200);

        $this->storage->expects(self::once())
            ->method('setex');

        $this->storage->expects(self::never())->method('del');

        $result = $this->otpService->verify($phone, 'login', '000000');

        self::assertFalse($result);
    }

    public function testVerifyExpiredOtpReturnsFalse(): void
    {
        $this->storage->expects(self::once())
            ->method('get')
            ->with('otp:login:+8613912345678')
            ->willReturn(false);

        $this->storage->expects(self::never())->method('del');

        $result = $this->otpService->verify('+8613912345678', 'login', '123456');

        self::assertFalse($result);
    }

    public function testMaxAttemptsDeletesOtp(): void
    {
        $hash = $this->otpService->hashOtp('999999');
        $stored = json_encode(['hash' => $hash, 'tries' => 4]); // 4 previous wrong attempts

        $returnValues = [$stored]; // TTL call will happen but we just need get once

        $this->storage->expects(self::once())
            ->method('get')
            ->willReturn($stored);

        // After the 5th wrong attempt, the key should be deleted
        $this->storage->expects(self::once())
            ->method('del')
            ->with('otp:login:+8613912345678');

        $this->storage->expects(self::never())->method('setex');

        $result = $this->otpService->verify('+8613912345678', 'login', '000000');

        self::assertFalse($result);
    }

    #[Group('low-value')]
    public function testHashOtpIsDeterministic(): void
    {
        $hash1 = $this->otpService->hashOtp('123456');
        $hash2 = $this->otpService->hashOtp('123456');

        self::assertSame($hash1, $hash2);
    }

    #[Group('low-value')]
    public function testHashOtpProducesDifferentHashes(): void
    {
        self::assertNotSame(
            $this->otpService->hashOtp('123456'),
            $this->otpService->hashOtp('654321'),
        );
    }
}
