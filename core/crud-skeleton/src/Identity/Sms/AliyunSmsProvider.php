<?php

declare(strict_types=1);

namespace App\Identity\Sms;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;
use Psr\Log\LoggerInterface;

final class AliyunSmsProvider implements SmsProviderInterface
{
    private bool $dryRun;

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $accessKeySecret,
        private readonly string $region,
        private readonly string $signName,
        private readonly ?LoggerInterface $logger = null,
        bool $dryRun = false,
    ) {
        $this->dryRun = $dryRun;
    }

    public function sendSms(string $phone, string $templateCode, array $params): bool
    {
        if ($this->dryRun) {
            $this->logger?->info('[AliyunSMS DRY-RUN]', [
                'phone' => $this->maskPhone($phone),
                'template' => $templateCode,
            ]);

            return true;
        }

        try {
            AlibabaCloud::accessKeyClient($this->accessKeyId, $this->accessKeySecret)
                ->regionId($this->region)
                ->asDefaultClient();

            $response = AlibabaCloud::rpc()
                ->product('Dysmsapi')
                ->version('2017-05-25')
                ->action('SendSms')
                ->method('POST')
                ->options([
                    'query' => [
                        'RegionId' => $this->region,
                        'PhoneNumbers' => $phone,
                        'SignName' => $this->signName,
                        'TemplateCode' => $templateCode,
                        'TemplateParam' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    ],
                ])
                ->request();

            $result = $response->toArray();
            $ok = ($result['Code'] ?? '') === 'OK';

            if (!$ok) {
                $this->logger?->error('[AliyunSMS] Send failed', [
                    'code' => $result['Code'] ?? 'unknown',
                    'message' => $result['Message'] ?? '',
                    'phone' => $this->maskPhone($phone),
                ]);
            }

            return $ok;
        } catch (ClientException|ServerException $e) {
            $this->logger?->error('[AliyunSMS] Exception: ' . $e->getMessage(), [
                'phone' => $this->maskPhone($phone),
            ]);

            return false;
        }
    }

    private function maskPhone(string $phone): string
    {
        if (mb_strlen($phone) <= 4) {
            return '***';
        }

        return mb_substr($phone, 0, 3) . '****' . mb_substr($phone, -4);
    }
}
