<?php

declare(strict_types=1);

namespace AlibabaCloud\Client {
    final class AlibabaCloud
    {
        /** @var array<string, string> */
        public static array $nextResult = ['Code' => 'OK'];
        public static ?\Throwable $nextException = null;

        public static function accessKeyClient(string $accessKeyId, string $accessKeySecret): AliyunSmsAccessKeyClientStub
        {
            return new AliyunSmsAccessKeyClientStub();
        }

        public static function rpc(): AliyunSmsRpcRequestStub
        {
            return new AliyunSmsRpcRequestStub();
        }
    }

    final class AliyunSmsAccessKeyClientStub
    {
        public function regionId(string $region): self
        {
            return $this;
        }

        public function asDefaultClient(): void
        {
        }
    }

    final class AliyunSmsRpcRequestStub
    {
        public function product(string $product): self
        {
            return $this;
        }

        public function version(string $version): self
        {
            return $this;
        }

        public function action(string $action): self
        {
            return $this;
        }

        public function method(string $method): self
        {
            return $this;
        }

        /** @param array<string, mixed> $options */
        public function options(array $options): self
        {
            return $this;
        }

        public function request(): AliyunSmsResponseStub
        {
            if (AlibabaCloud::$nextException !== null) {
                throw AlibabaCloud::$nextException;
            }

            return new AliyunSmsResponseStub(AlibabaCloud::$nextResult);
        }
    }

    final class AliyunSmsResponseStub
    {
        /** @param array<string, string> $result */
        public function __construct(private readonly array $result)
        {
        }

        /** @return array<string, string> */
        public function toArray(): array
        {
            return $this->result;
        }
    }
}

namespace AlibabaCloud\Client\Exception {
    class ClientException extends \Exception
    {
    }

    class ServerException extends \Exception
    {
    }
}

namespace App\Tests\UnitTest\Identity\Sms {
    use AlibabaCloud\Client\AlibabaCloud;
    use AlibabaCloud\Client\Exception\ClientException;
    use App\Identity\Sms\AliyunSmsProvider;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\LoggerInterface;

    final class AliyunSmsProviderLiveBranchTest extends TestCase
    {
        protected function setUp(): void
        {
            AlibabaCloud::$nextResult = ['Code' => 'OK'];
            AlibabaCloud::$nextException = null;
        }

        public function testSendSmsReturnsTrueWhenAliyunReturnsOk(): void
        {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::never())->method('error');

            $provider = new AliyunSmsProvider('ak', 'sk', 'cn-hangzhou', 'Sign', $logger);

            self::assertTrue($provider->sendSms('+8613912345678', 'SMS_LOGIN', ['code' => '123456']));
        }

        public function testSendSmsLogsMaskedPhoneWhenAliyunReturnsFailure(): void
        {
            AlibabaCloud::$nextResult = ['Code' => 'isv.BUSINESS_LIMIT_CONTROL', 'Message' => 'limited'];

            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())
                ->method('error')
                ->with(
                    '[AliyunSMS] Send failed',
                    self::callback(static function (array $context): bool {
                        return ($context['code'] ?? null) === 'isv.BUSINESS_LIMIT_CONTROL'
                            && ($context['message'] ?? null) === 'limited'
                            && ($context['phone'] ?? null) === '+86****5678';
                    })
                );

            $provider = new AliyunSmsProvider('ak', 'sk', 'cn-hangzhou', 'Sign', $logger);

            self::assertFalse($provider->sendSms('+8613912345678', 'SMS_LOGIN', ['code' => '123456']));
        }

        public function testSendSmsLogsExceptionAndReturnsFalse(): void
        {
            AlibabaCloud::$nextException = new ClientException('network unavailable');

            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())
                ->method('error')
                ->with(
                    '[AliyunSMS] Exception: network unavailable',
                    self::callback(static fn (array $context): bool => ($context['phone'] ?? null) === '+86****5678')
                );

            $provider = new AliyunSmsProvider('ak', 'sk', 'cn-hangzhou', 'Sign', $logger);

            self::assertFalse($provider->sendSms('+8613912345678', 'SMS_LOGIN', ['code' => '123456']));
        }
    }
}
