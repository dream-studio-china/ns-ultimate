<?php

declare(strict_types=1);

namespace App\Wechat\Service;

use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use EasyWeChat\Pay\Application as Pay;

final class WechatService implements WechatServiceInterface
{
    private ?MiniApp $miniApp = null;
    private ?OfficialAccount $officialAccount = null;
    private ?Pay $payApp = null;

    public function __construct(
        // Mini Program
        private readonly string $miniappAppId,
        private readonly string $miniappSecret,

        // Official Account
        private readonly string $officialAppId,
        private readonly string $officialSecret,
        private readonly string $officialToken,
        private readonly string $officialAesKey,

        // WeChat Pay
        private readonly string $payMchId,
        private readonly string $paySecretKey,
        private readonly string $payPrivateKeyPath,
        private readonly string $payCertificatePath,
        private readonly string $payPlatformCertPath = '',
        private readonly string $payPubKeyId = '',
        private readonly string $payPubKeyPath = '',
    ) {}

    public function getMiniApp(): MiniApp
    {
        if ($this->miniApp === null) {
            $this->miniApp = new MiniApp([
                'app_id' => $this->miniappAppId,
                'secret' => $this->miniappSecret,
                'http' => [
                    'throw' => true,
                    'timeout' => 5.0,
                ],
            ]);
        }
        return $this->miniApp;
    }

    public function getOfficialAccount(): OfficialAccount
    {
        if ($this->officialAccount === null) {
            $this->officialAccount = new OfficialAccount([
                'app_id' => $this->officialAppId,
                'secret' => $this->officialSecret,
                'token' => $this->officialToken,
                'aes_key' => $this->officialAesKey,
                'http' => [
                    'throw' => true,
                    'timeout' => 5.0,
                ],
            ]);
        }
        return $this->officialAccount;
    }

    public function getPayApp(): Pay
    {
        if ($this->payApp === null) {
            $config = [
                'mch_id' => (int) $this->payMchId,
                'secret_key' => $this->paySecretKey,
                'private_key' => $this->payPrivateKeyPath,
                'certificate' => $this->payCertificatePath,
                'http' => [
                    'throw' => true,
                    'timeout' => 5.0,
                ],
            ];

            if ($this->payPlatformCertPath !== '') {
                $config['platform_certs'] = [$this->payPlatformCertPath];
            }
            if ($this->payPubKeyId !== '' && $this->payPubKeyPath !== '') {
                $config['platform_certs'][$this->payPubKeyId] = $this->payPubKeyPath;
            }

            $this->payApp = new Pay($config);
        }
        return $this->payApp;
    }

    /**
     * @internal For testing: inject a pre-configured MiniApp Application
     */
    public function setMiniApp(MiniApp $app): void
    {
        $this->miniApp = $app;
    }

    /**
     * @internal For testing: inject a pre-configured OfficialAccount Application
     */
    public function setOfficialAccount(OfficialAccount $app): void
    {
        $this->officialAccount = $app;
    }

    /**
     * @internal For testing: inject a pre-configured Pay Application
     */
    public function setPayApp(Pay $app): void
    {
        $this->payApp = $app;
    }

    /**
     * Mini Program: code2Session
     * @return array{openid: string, unionid?: string, session_key: string}
     */
    public function code2Session(string $jsCode): array
    {
        $app = $this->getMiniApp();
        $response = $app->getClient()->get('/sns/jscode2session', [
            'query' => [
                'appid' => $app->getAccount()->getAppId(),
                'secret' => $app->getAccount()->getSecret(),
                'js_code' => $jsCode,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $data = $response->toArray(false);

        if (!isset($data['openid'])) {
            throw new \RuntimeException(
                'WeChat code2Session failed: ' . ($data['errmsg'] ?? 'unknown error')
            );
        }

        return [
            'openid' => $data['openid'],
            'unionid' => $data['unionid'] ?? null,
            'session_key' => $data['session_key'] ?? '',
        ];
    }

    /**
     * Mini Program: get phone number
     * @return array{phoneNumber: string}
     */
    public function getPhoneNumber(string $code): array
    {
        $response = $this->getMiniApp()->getClient()->postJson('wxa/business/getuserphonenumber', [
            'code' => $code,
        ]);

        $data = $response->toArray(false);

        if (!isset($data['phone_info']['phoneNumber'])) {
            throw new \RuntimeException(
                'WeChat getPhoneNumber failed: ' . ($data['errmsg'] ?? 'unknown error')
            );
        }

        return [
            'phoneNumber' => $data['phone_info']['phoneNumber'],
        ];
    }

    /**
     * Official Account: generate OAuth redirect URL
     */
    public function getOAuthRedirectUrl(string $callbackUrl): string
    {
        $oauth = $this->getOfficialAccount()->getOauth();

        return $oauth->scopes(['snsapi_userinfo'])->redirect($callbackUrl);
    }

    /**
     * Official Account: exchange code for user info
     * @return array{openid: string, unionid?: string, nickname: string, avatar: string, sex: int, province: string, city: string, country: string}
     */
    public function getOAuthUser(string $code): array
    {
        try {
            $oauth = $this->getOfficialAccount()->getOauth();
            $user = $oauth->userFromCode($code);

            return [
                'openid' => $user->getId(),
                'nickname' => $user->getNickname() ?? '',
                'avatar' => $user->getAvatar() ?? '',
                'sex' => $user->getRaw()['sex'] ?? 0,
                'province' => $user->getRaw()['province'] ?? '',
                'city' => $user->getRaw()['city'] ?? '',
                'country' => $user->getRaw()['country'] ?? '',
                'unionid' => $user->getRaw()['unionid'] ?? null,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException('WeChat OAuth failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
