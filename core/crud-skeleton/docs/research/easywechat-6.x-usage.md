# EasyWeChat 6.x Usage Notes

> Research source: https://easywechat.com/6.x/
> Focus: Mini Program, login/openid, and WeChat Pay APIs.

## 1. Mini Program

### 1.1 Install And Instantiate

EasyWeChat 6.x uses module-specific `Application` classes. For Mini Program:

```php
use EasyWeChat\MiniApp\Application;

$config = [
    'app_id' => 'wx3cf0f39249eb0exx',
    'secret' => 'f1c242f4f28f735d4687abb469072axx',
    'token' => 'easywechat',
    'aes_key' => '......',
    'http' => [
        'throw' => true,
        'timeout' => 5.0,
        'retry' => true,
    ],
];

$app = new Application($config);
```

Common entry points:

```php
$client = $app->getClient();       // API client, auto handles access_token
$server = $app->getServer();       // server callback handling
$config = $app->getConfig();       // config repository
$token = $app->getAccessToken();   // access token object
$utils = $app->getUtils();         // mini app utility helpers
$account = $app->getAccount();     // app_id / secret / token / aes_key info
```

Access token value:

```php
$accessToken = $app->getAccessToken();
$token = $accessToken->getToken();
```

### 1.2 Generic API Calls

Use `getClient()` for arbitrary WeChat Mini Program APIs:

```php
$response = $app->getClient()->postJson('/wxa/getwxacodeunlimit', [
    'scene' => '123',
    'page' => 'pages/index/index',
    'width' => 430,
    'check_path' => false,
]);

$path = $response->saveAs('/tmp/wxacode-123.png');
```

### 1.3 Get Phone Number

Mini Program phone number API uses a frontend `code` passed to backend:

```php
$data = [
    'code' => (string) request()->get('code'),
];

$response = $app->getClient()->postJson('wxa/business/getuserphonenumber', $data);
```

## 2. Login And OpenID

### 2.1 Web OAuth Flow

EasyWeChat OAuth is mainly documented for Official Account / Open Platform web authorization.

Typical flow:

1. User opens a protected business page.
2. Backend detects missing login/session.
3. Backend redirects user to WeChat OAuth URL.
4. WeChat redirects back with `code` and `state`.
5. Backend exchanges `code` for OAuth user info.
6. Backend stores `openid` and optional profile info in the local user/session.

### 2.2 Start Authorization

```php
$oauth = $app->getOauth();

$redirectUrl = $oauth->scopes(['snsapi_userinfo'])->redirect();

// Or set callback/current URL explicitly:
$redirectUrl = $oauth->scopes(['snsapi_userinfo'])->redirect($request->fullUrl());
```

Then return a framework redirect response:

```php
return redirect($redirectUrl);
```

### 2.3 Handle Callback And Get OpenID

```php
$code = $_GET['code'];
$user = $oauth->userFromCode($code);
```

Useful methods:

```php
$openid = $user->getId();          // WeChat openid
$nickname = $user->getNickname();
$name = $user->getName();
$avatar = $user->getAvatar();
$raw = $user->getRaw();            // original API response
$accessToken = $user->getAccessToken();
$refreshToken = $user->getRefreshToken();
$expiresIn = $user->getExpiresIn();
$tokenResponse = $user->getTokenResponse();
```

Important notes:

- `$user->getId()` is the WeChat `openid`.
- With `snsapi_base`, the returned user generally only contains `id/openid`.
- With `snsapi_userinfo`, nickname/avatar and richer profile fields are available after user consent.

### 2.4 Mini Program Login Note

For Mini Program login, EasyWeChat 6.x primarily exposes the generic API client. In backend code, pass frontend `js_code` to the WeChat auth endpoint through `$app->getClient()`, then persist the returned `openid`/`unionid` according to the official Mini Program login contract.

Example shape:

```php
$response = $app->getClient()->get('/sns/jscode2session', [
    'query' => [
        'appid' => $app->getAccount()->getAppId(),
        'secret' => $app->getAccount()->getSecret(),
        'js_code' => $jsCode,
        'grant_type' => 'authorization_code',
    ],
]);

$data = $response->toArray(false);
$openid = $data['openid'] ?? null;
$unionid = $data['unionid'] ?? null;
$sessionKey = $data['session_key'] ?? null;
```

## 3. WeChat Pay

### 3.1 Instantiate Pay Application

```php
use EasyWeChat\Pay\Application;

$config = [
    'mch_id' => 1360649000,
    'private_key' => __DIR__ . '/certs/apiclient_key.pem',
    'certificate' => __DIR__ . '/certs/apiclient_cert.pem',
    'secret_key' => 'APIv3_KEY',
    'v2_secret_key' => 'V2_API_KEY',
    'platform_certs' => [
        // Platform cert mode:
        // 'SERIAL_NO' => '/path/to/wechatpay/cert.pem',

        // WeChat Pay public key mode:
        // 'PUB_KEY_ID_...' => '/path/to/wechatpay/pubkey.pem',
    ],
    'http' => [
        'throw' => true,
        'timeout' => 5.0,
    ],
];

$app = new Application($config);
```

Useful entry points:

```php
$client = $app->getClient();       // WeChat Pay API client
$utils = $app->getUtils();         // payment frontend config helpers
$config = $app->getConfig();
$merchant = $app->getMerchant();
$validator = $app->getValidator(); // signature validator
$server = $app->getServer();       // payment/refund notify server
```

Merchant helpers:

```php
$merchant->getMerchantId();
$merchant->getPrivateKey();
$merchant->getCertificate();
$merchant->getSecretKey();
$merchant->getV2SecretKey();
$merchant->getPlatformCert($serial);
$merchant->getPlatformCerts();
```

### 3.2 JSAPI Order

JSAPI requires payer `openid`:

```php
$response = $app->getClient()->postJson('v3/pay/transactions/jsapi', [
    'mchid' => (string) $app->getMerchant()->getMerchantId(),
    'out_trade_no' => $outTradeNo,
    'appid' => $miniAppIdOrOfficialAccountAppId,
    'description' => $description,
    'notify_url' => $notifyUrl,
    'amount' => [
        'total' => $amountInCents,
        'currency' => 'CNY',
    ],
    'payer' => [
        'openid' => $openid,
    ],
]);

$result = $response->toArray(false);
```

### 3.3 Native Order

```php
$response = $app->getClient()->postJson('v3/pay/transactions/native', [
    'mchid' => (string) $app->getMerchant()->getMerchantId(),
    'out_trade_no' => $outTradeNo,
    'appid' => $appId,
    'description' => $description,
    'notify_url' => $notifyUrl,
    'amount' => [
        'total' => $amountInCents,
        'currency' => 'CNY',
    ],
]);

$result = $response->toArray(false);
```

### 3.4 Query Order

By merchant order number:

```php
$response = $app->getClient()->get("v3/pay/transactions/out-trade-no/{$outTradeNo}", [
    'query' => [
        'mchid' => $app->getMerchant()->getMerchantId(),
    ],
]);

$order = $response->toArray();
```

By WeChat transaction id:

```php
$response = $app->getClient()->get("v3/pay/transactions/id/{$transactionId}", [
    'query' => [
        'mchid' => $app->getMerchant()->getMerchantId(),
    ],
]);
```

### 3.5 Validate API Response Signature

```php
$response = $app->getClient()->postJson('v3/pay/transactions/jsapi', [...]);

try {
    $app->getValidator()->validate($response->toPsrResponse());
} catch (\Throwable $e) {
    // Signature verification failed
}
```

### 3.6 Payment Notify Handling

Payment notifications should be verified and treated as untrusted until validated. EasyWeChat provides server handlers:

```php
$server = $app->getServer();

$server->handlePaid(function ($message, \Closure $next) use ($app) {
    // $message->out_trade_no: merchant order no
    // $message->transaction_id: WeChat transaction id
    // $message->payer['openid']: payer openid

    $app->getValidator()->validate($app->getRequest());

    // Recommended: query WeChat order by out_trade_no and use query result as source of truth.
    // Then update local invoice/order idempotently.

    return $next($message);
});

return $server->serve();
```

Default successful response is similar to:

```php
['code' => 'SUCCESS', 'message' => '成功']
```

Important notes:

- Notify URL must use HTTPS for production WeChat Pay.
- Callback route usually needs CSRF disabled.
- Do not blindly trust callback payload. Verify signature and query the order state when possible.
- Use `out_trade_no` as local invoice/order lookup key.
- Use idempotency because WeChat can retry callbacks.

### 3.7 Refund Notify Handling

```php
$server = $app->getServer();

$server->handleRefunded(function ($message, \Closure $next) {
    // $message->out_trade_no
    // $message->transaction_id
    // Handle local refund state idempotently.
    return $next($message);
});

return $server->serve();
```

Some refund notifications may still use v2-style XML in certain merchant-platform flows. EasyWeChat docs recommend using a dedicated refund notify route if this appears in production.

### 3.8 Read Raw Request Message

```php
$message = $server->getRequestMessage();

$eventType = $message->getEventType();
$original = $message->getOriginalAttributes();
```

The parsed `$message` is decrypted by SDK. `getOriginalAttributes()` returns encrypted/original callback attributes.

## 4. Payment Frontend Config Helpers

EasyWeChat Pay utils can generate frontend payment configs from `prepay_id`.

### 4.1 WeixinJSBridge

```php
$utils = $app->getUtils();
$config = $utils->buildBridgeConfig($prepayId, $appId, 'RSA');
```

Frontend:

```js
WeixinJSBridge.invoke('getBrandWCPayRequest', {
  timeStamp: config.timeStamp,
  nonceStr: config.nonceStr,
  package: config.package,
  signType: config.signType,
  paySign: config.paySign
}, function (res) {
  if (res.err_msg === 'get_brand_wcpay_request:ok') {
    // User-side success callback; still rely on server notify/query for final state.
  }
});
```

### 4.2 JSSDK `wx.chooseWXPay`

```php
$config = $utils->buildSdkConfig($prepayId, $appId, 'RSA');
```

### 4.3 Mini Program `wx.requestPayment`

```php
$config = $utils->buildMiniAppConfig($prepayId, $miniAppId, 'RSA');
```

Frontend:

```js
wx.requestPayment({
  timeStamp: config.timeStamp,
  nonceStr: config.nonceStr,
  package: config.package,
  signType: config.signType,
  paySign: config.paySign,
  success(res) {
    // Client success callback only. Server notify/query remains source of truth.
  }
});
```

### 4.4 APP Pay

```php
$config = $utils->buildAppConfig($prepayId, $appId);
```

## 5. Sensitive Field Encryption

EasyWeChat 6.17.0+ supports WeChat Pay public-key encryption for sensitive fields:

```php
$utils = $app->getUtils();

$encrypted = $utils->encryptWithRsaPublicKey($plaintext, $serialOrPubKeyId);
```

When sending requests that require serial headers:

```php
$response = $app->getClient()
    ->withSerialHeader('PUB_KEY_ID_123456')
    ->postJson('v3/applyment4sub/applyment/', [
        'business_code' => '12345678',
        'contact_info' => [
            'contact_name' => $utils->encryptWithRsaPublicKey('张三', 'PUB_KEY_ID_123456'),
        ],
    ]);
```

## 6. Practical Integration Notes For This Project

- Store all amounts as integer cents. WeChat Pay `amount.total` also uses cents.
- Map this project's invoice `outTradeNo` to WeChat Pay `out_trade_no`.
- Store WeChat Pay `transaction_id` as provider transaction id.
- Store payer `openid` in local identity/payment metadata before creating JSAPI orders.
- Use server-side notify/query to mark invoices paid. Frontend success callbacks are not authoritative.
- Webhook handling should be idempotent and signature-verified.
- For Mini Program payment, backend should return `buildMiniAppConfig($prepayId, $miniAppId, 'RSA')` payload to frontend.
- Keep merchant credentials, API keys, private keys, platform cert/public key paths in environment or secret config, never committed.

## 7. Source Pages

- https://easywechat.com/6.x/mini-app
- https://easywechat.com/6.x/mini-app/examples.html
- https://easywechat.com/6.x/oauth.html
- https://easywechat.com/6.x/pay/index.html
- https://easywechat.com/6.x/pay/examples.html
- https://easywechat.com/6.x/pay/server.html
- https://easywechat.com/6.x/pay/utils.html
