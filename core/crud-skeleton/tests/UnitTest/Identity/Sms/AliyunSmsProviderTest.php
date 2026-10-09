<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Sms;

use App\Identity\Sms\AliyunSmsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AliyunSmsProviderTest extends TestCase
{
    public function testDryRunReturnsTrueAndMasksLongPhoneInLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                '[AliyunSMS DRY-RUN]',
                self::callback(static function (array $context): bool {
                    return ($context['phone'] ?? null) === '+86****5678'
                        && ($context['template'] ?? null) === 'SMS_LOGIN';
                })
            );

        $provider = new AliyunSmsProvider(
            'ak',
            'sk',
            'cn-hangzhou',
            'SignName',
            $logger,
            true,
        );

        self::assertTrue($provider->sendSms('+8613912345678', 'SMS_LOGIN', ['code' => '123456']));
    }

    public function testDryRunMasksShortPhoneAsAsterisks(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                '[AliyunSMS DRY-RUN]',
                self::callback(static function (array $context): bool {
                    return ($context['phone'] ?? null) === '***';
                })
            );

        $provider = new AliyunSmsProvider(
            'ak',
            'sk',
            'cn-hangzhou',
            'SignName',
            $logger,
            true,
        );

        self::assertTrue($provider->sendSms('1234', 'SMS_VERIFY', ['code' => '654321']));
    }
}
