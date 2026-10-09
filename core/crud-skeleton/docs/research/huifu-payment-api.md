# Huifu (汇付斗拱) Payment API Research

> From farm-neighbor legacy `HuifuBundle` + `PayStrategy` + official SDK (`huifurepo/dg-php-sdk` v2.0.11).
> API docs: https://paas.huifu.com/open/doc/api/

## 1. Environment

| Environment | Base URL |
|-------------|----------|
| Production | `https://api.huifu.com` |
| Sandbox/Test | `https://spin-test.cloudpnr.com` |

Request envelope for all V2 APIs:

```json
{
    "sys_id": "6666000135263684",
    "product_id": "YMFZS",
    "data": { ... },
    "sign": "<base64 RSA-SHA256>"
}
```

---

## 2. Core Signing — RSA-SHA256 (from official SDK)

These are the exact implementations from `BsPaySdk\core\BsPayTools.php` — the official Huifu PHP SDK.

### 2.1 Request signing

```php
// File: BsPaySdk/core/BsPayTools.php (official SDK v2.0.11)
public static function sha_with_rsa_sign($data, $rsaPrivateKey, $alg=OPENSSL_ALGO_SHA256){
    $key = "-----BEGIN PRIVATE KEY-----\n"
        . wordwrap($rsaPrivateKey, 64, "\n", true)
        . "\n-----END PRIVATE KEY-----";
    $signature= '';
    try {
        openssl_sign($data, $signature, $key, $alg);
    } catch (\Exception $e) {
        echo $e->getMessage();
    }
    return base64_encode($signature);
}
```

**Usage:**
1. Build request `data` as associative array.
2. `ksort($data)`
3. `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`
4. `sha_with_rsa_sign($json, $rsaPrivateKey)` → base64 signature
5. Wrap into `{sys_id, product_id, data, sign}` envelope

### 2.2 Response / notify verification

```php
// File: BsPaySdk/core/BsPayTools.php (official SDK)

// Simple verify (raw string)
public static function verifySign($signature, $data, $rsaPublicKey, $alg=OPENSSL_ALGO_SHA256){
    $key = "-----BEGIN PUBLIC KEY-----\n"
        . wordwrap($rsaPublicKey, 64, "\n", true)
        . "\n-----END PUBLIC KEY-----";
    return openssl_verify($data, base64_decode($signature), $key, $alg);
}

// Verify with ksort (for API response data arrays)
public static function verifySign_sort($signature, $data, $rsaPublicKey, $alg=OPENSSL_ALGO_SHA256){
    $key = "-----BEGIN PUBLIC KEY-----\n"
        . wordwrap($rsaPublicKey, 64, "\n", true)
        . "\n-----END PUBLIC KEY-----";
    ksort($data);
    $data = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $data = str_replace("\n","\\n",$data);
    $data = str_replace('"enter_fee":0,','"enter_fee":0.00,',$data);
    return openssl_verify($data, base64_decode($signature), $key, $alg);
}
```

**Note:** `verifySign_sort()` has a hardcoded workaround for `enter_fee` field format bug. New implementations should be aware of this.

### 2.3 Sensitive field encryption

```php
// File: BsPaySdk/core/BsPayTools.php (official SDK)
public static function encrypt_with_rsa_pubkey($data, $rsaPublicKey, $padding=OPENSSL_PKCS1_PADDING){
    $key = "-----BEGIN PUBLIC KEY-----\n"
        . wordwrap($rsaPublicKey, 64, "\n", true)
        . "\n-----END PUBLIC KEY-----";
    $encryptResult= '';
    try {
        openssl_public_encrypt($data, $encryptResult, $key, $padding);
    } catch (\Exception $e) {
        echo $e->getMessage();
    }
    return base64_encode($encryptResult);
}
```

### 2.4 Request envelope builder (from official SDK)

```php
// File: BsPaySdk/core/BsPayRequestV2.php — createBody()
private function createBody(MerConfig $merChantConfig, $post_data, $file = null){
    $body = array();
    $body['sys_id'] = $merChantConfig->sys_id;
    $body['product_id'] = $merChantConfig->product_id;
    ksort($post_data);
    $body['data'] = $post_data;
    $sign = BsPayTools::sha_with_rsa_sign(
        json_encode($post_data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $merChantConfig->rsa_merch_private_key);
    $body['sign'] = $sign;
    if(!empty($file)){
        $body['file'] = $file;
        $body['data'] = json_encode($post_data, JSON_UNESCAPED_UNICODE);
    }
    return $body;
}

// Response verification (after curl)
$resp_sign = isset($this->rspDatas['sign']) ? $this->rspDatas['sign'] : '';
$resp_data = isset($this->rspDatas['data']) ? $this->rspDatas['data'] : '';
if (!BsPayTools::verifySign_sort($resp_sign, $resp_data, $merConfig->rsa_huifu_public_key)) {
    $this->error = ['code' => 'RESP_SIGN_VERIFY_FAILED', 'msg' => '...'];
}
```

### 2.5 SDK init / MerConfig

```php
// File: BsPaySdk/core/BsPay.php — init()
public static function init($config_info, $is_object=false, $merchantKey = 'default'){
    // Accepts array or JSON file path
    // Config keys: product_id, sys_id, rsa_merch_private_key, rsa_huifu_public_key
    $merConfig = new MerConfig();
    $merConfig->product_id = $config_obj['product_id'] ?? '';
    $merConfig->sys_id = $config_obj['sys_id'] ?? '';
    $merConfig->rsa_merch_private_key = $config_obj['rsa_merch_private_key'] ?? '';
    $merConfig->rsa_huifu_public_key = $config_obj['rsa_huifu_public_key'] ?? '';
    self::addMerConfig($merchantKey, $merConfig);
}
```

SDK function code convention: `V2_TRADE_PAYMENT_JSPAY` → URL `/v2/trade/payment/jspay` (dots → slashes). Production vs test base URL determined by `PROD_MODE` constant.

---

## 3. Payment APIs

### 3.1 Unified Order (JSAPI/MiniApp/Native/H5/APP)

```
POST /v2/trade/payment/jspay
```

**Request `data` fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `req_date` | string(8) | Yes | Ymd format, e.g. `"20230725"` |
| `req_seq_id` | string | Yes | Merchant order number, unique |
| `huifu_id` | string | Yes | Merchant `sys_id` or sub-merchant `huifu_id` |
| `trade_type` | string | Yes | `T_JSAPI`, `T_MINIAPP`, `T_NATIVE`, `T_APP`, `T_H5` |
| `trans_amt` | string | Yes | Amount in YUAN, e.g. `"10.00"` |
| `goods_desc` | string | Yes | Product description (UTF-8) |
| `notify_url` | string | Yes | Async callback URL |
| `wx_data` | string | Conditional | JSON string: miniapp → `{"sub_appid":"...","sub_openid":"..."}`, JSAPI → `{"openid":"..."}` |
| `time_expire` | string | No | Order expiry |
| `alipay_data` | string | No | Alipay channel data |
| `acct_split_bunch` | string | No | Split settlement config |
| `risk_check_data` | string | No | Risk control |
| `terminal_device_data` | string | No | POS terminal data |
| `extend_pay_data` | string | No | Extended pay data |

**Response `data` fields:**

| Field | Description |
|-------|-------------|
| `resp_code` | `00000100` (order created), `00000000` (success), other = error |
| `resp_desc` | Description |
| `hf_seq_id` | Huifu transaction seq ID |
| `req_seq_id` | Echo back merchant order no |
| `trans_stat` | `P` = processing, `S` = success, `F` = failed |
| `bank_code` / `bank_message` | Channel info |
| `pay_info` | JSON: `{appId, timeStamp, package, paySign, signType, nonceStr}` for `wx.requestPayment` |
| `trans_amt` | Amount |

**Sample successful jspay response:**

```json
{
    "data": {
        "bank_code": "SUCCESS",
        "bank_message": "用户支付中",
        "hf_seq_id": "002900TOP2B230725151155P901ac139fc400000",
        "huifu_id": "6666000135263684",
        "pay_info": "{\"timeStamp\":\"1690269116\",\"package\":\"prepay_...\",\"paySign\":\"...\",\"appId\":\"wx...\",\"signType\":\"RSA\",\"nonceStr\":\"...\"}",
        "req_date": "20230725",
        "req_seq_id": "5534c98f980d6b02f0cc",
        "resp_code": "00000100",
        "resp_desc": "下单成功",
        "trade_type": "T_MINIAPP",
        "trans_amt": "10.00",
        "trans_stat": "P"
    },
    "sign": "..."
}
```

### 3.2 Scanpay Query

```
POST /v2/trade/payment/scanpay/query
```

Request: `org_req_seq_id`, `org_req_date`, `huifu_id`.

### 3.3 Close Order

```
POST /v2/trade/payment/scanpay/close
```

Request: `req_date`, `req_seq_id`, `huifu_id`, `org_req_date`, `org_req_seq_id`.

### 3.4 Refund

```
POST /v2/trade/payment/scanpay/refund
```

Request: `req_date`, `req_seq_id` (new refund order no), `huifu_id`, `ord_amt` (yuan string), `org_req_date`, `org_req_seq_id`.

---

## 4. Asynchronous Notification

### 4.1 Format

POST, Content-Type: `application/x-www-form-urlencoded`. Three fields:

| Field | Description |
|-------|-------------|
| `resp_code` | Response code |
| `resp_data` | JSON string of payload |
| `sign` | RSA-SHA256 of raw `resp_data` string |

### 4.2 Notification payload (`resp_data` JSON)

```json
{
    "bank_code": "00",
    "bank_message": "交易受理成功",
    "end_time": "20221202141817",
    "hf_seq_id": "0029000topB221202141742P452c0a8305300000",
    "huifu_id": "6666000103977566",
    "out_trans_id": "4200001639202212023117210870",
    "party_order_id": "2212025146252905407",
    "req_date": "20221202",
    "req_seq_id": "20211669961862",
    "resp_code": "00000000",
    "trans_amt": "1.03",
    "trans_stat": "S",
    "trans_time": "141742",
    "trans_type": "T_INATIVE",
    "wx_response": {
        "transaction_id": "4200001639202212023117210870",
        "openid": "o3CTo59HfKt9RPn7a2v4LP5ZmS2U",
        "sub_openid": "oFug15eXZOh1Ywbo_8aFSXz-9eE0",
        "total_fee": "1.03",
        "trade_type": "NATIVE",
        "time_end": "20221202141817"
    }
}
```

### 4.3 Status codes

| Code | Meaning |
|------|---------|
| `00000000` | Success |
| `00000100` | Processing |
| Other | Error — see `resp_desc` |

---

## 5. Legacy PayStrategy Implementation (farm-neighbor)

### 5.1 PayStrategy interface

```php
// File: MainBundle/Payment/PayStrategy.php
namespace MainBundle\Payment;
use MainBundle\Entity\Invoice;

interface PayStrategy
{
    function pay(Invoice $invoice);
    function notify();
    function refund(Invoice $invoice, $amount);
}
```

### 5.2 HuifuPayStrategy — full implementation

```php
// File: MainBundle/Payment/HuifuPayStrategy.php (560 lines)
namespace MainBundle\Payment;

class HuifuPayStrategy implements PayStrategy
{
    const PAYMENT = 'huifu';

    protected $container;
    private $config;
    private $gateway = 'wechat';
    private $tradeType = 'T_MINIAPP';
    private $paymentApiUrl = 'https://api.huifu.com/v2/trade/payment/jspay';
    private $refundApiUrl = 'https://api.huifu.com/v2/trade/payment/scanpay/refund';
    private $org_req_date = null;

    function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->getParameter('huifu.payment');
    }

    // --- config ---
    function config(array $conf): HuifuPayStrategy
    {
        if(array_key_exists('trade_type', $conf)) $this->tradeType = $conf['trade_type'];
        if(array_key_exists('gateway', $conf) && $conf['gateway']) $this->gateway = $conf['gateway'];

        switch ($this->gateway) {
            case 'jspay':     $this->paymentApiUrl = 'https://api.huifu.com/v2/trade/payment/jspay'; break;
            case 'quickpay':  $this->paymentApiUrl = 'https://api.huifu.com/v2/trade/onlinepayment/quickpay/frontpay'; break;
            case 'scanpay-query':
                $this->org_req_date = $conf['org_req_date'];
                $this->paymentApiUrl = 'https://api.huifu.com/v2/trade/payment/scanpay/query';
                break;
            default: throw new ValidatorException('Invalid gateway: ' . $this->gateway);
        }
        return $this;
    }

    // --- pay ---
    function pay(Invoice $invoice)
    {
        if($invoice->getStatus() != Invoice::STATUS_PENDING)
            throw new ValidatorException('Current invoice cannot be pay.');

        $curl = new Curl();
        $generateCode = function($length = 8) {
            return strtoupper(substr(md5(microtime().rand()), 5, $length));
        };

        $extraData = json_decode(json_encode($invoice->getExtraData()), true);

        // Return saved payment if exists
        if($this->gateway != 'scanpay-query' && array_key_exists('payment', $extraData)) {
            return ['invoice' => $invoice, 'payment' => $extraData['payment']];
        }

        $invoiceOutTradeNo = $generateCode(20);

        // Build request data
        switch ($this->gateway) {
            case 'jspay':
                $data = [
                    'req_date' => (new \DateTime())->format('Ymd'),
                    'req_seq_id' => $invoiceOutTradeNo,
                    'huifu_id' => $this->config['sys_id'],
                    'trade_type' => $this->tradeType,
                    'trans_amt' => $invoice->getAmount(),
                    'goods_desc' => 'Invoice Purchase',
                    'notify_url' => $this->generateNotifyUrl(),
                ];
                // wx_data for miniapp: sub_appid + sub_openid
                // wx_data for JSAPI: openid
                break;

            case 'quickpay':
                $data = [
                    'req_date' => (new \DateTime())->format('Ymd'),
                    'req_seq_id' => $invoiceOutTradeNo,
                    'huifu_id' => $this->config['sys_id'],
                    'trans_amt' => $invoice->getAmount(),
                    'notify_url' => $this->generateNotifyUrl(),
                    'extend_pay_data' => json_encode(['goods_short_name'=>'...','biz_tp'=>'100001','gw_chnnl_tp'=>'02']),
                    'terminal_device_data' => json_encode(['device_type'=>'1','device_ip'=>'...']),
                    'risk_check_data' => json_encode(['ip_addr'=>'...']),
                ];
                break;

            case 'scanpay-query':
                $data = [
                    'org_req_seq_id' => $invoiceOutTradeNo,
                    'org_req_date' => $this->org_req_date ?: substr($invoiceOutTradeNo, 0, 8),
                    'huifu_id' => $this->config['sys_id'],
                ];
                break;
        }

        // Sign and send
        ksort($data);
        $sign = self::sha_with_rsa_sign(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->config['rsa_merch_private_key']
        );

        $postData = [
            'sys_id' => $this->config['sys_id'],
            'product_id' => $this->config['product_id'],
            'data' => $data,
            'sign' => $sign
        ];

        $curl->setHeader('Content-Type', 'application/json; charset=utf-8');
        $curl->post($this->paymentApiUrl, json_encode($postData));
        $payment = json_decode($curl->getResponse(), true)['data'];

        if($payment['resp_code'] == '00000100' || $payment['resp_code'] == '00000000') {
            $invoice->setPayment(self::PAYMENT);
            $invoice->setGateway($this->gateway);
            $invoice->setTradeType($this->tradeType);
            $extraData['payment'] = $payment;
            $invoice->setExtraData($extraData);
            $invoice->setOutTradeNo($invoiceOutTradeNo);
            // For scanpay-query: if trans_stat=S, set transactionId + status=PAID
            return ['invoice' => $invoice, 'payment' => $payment];
        }
        throw new ValidatorException($payment['resp_desc']);
    }

    // --- notify ---
    function notify()
    {
        $invoiceService = $this->container->get(InvoiceService::class);
        $request = $this->container->get('request_stack')->getCurrentRequest();

        // Huifu sends application/x-www-form-urlencoded, NOT JSON!
        parse_str($request->getContent(), $content);

        if($content['resp_code'] === '00000000') {
            // Verify signature of resp_data string
            $rsaPubKey = $this->container->getParameter('huifu.payment')['rsa_huifu_public_key'];
            if(!self::verifySign($content['sign'], $content['resp_data'], $rsaPubKey))
                throw new ValidatorException('Checksum failure');

            $data = json_decode($content['resp_data'], true);

            // Semaphore lock to prevent duplicate processing
            $receivedOrderId = "RECV_ORD_ID_{$data['req_seq_id']}";
            $factory = new Factory(new SemaphoreStore());
            $lock = $factory->createLock($receivedOrderId, 30);

            try {
                if(!$lock->acquire()) throw new ValidatorException('Cannot acquire the lock');

                $entity = $invoiceService->get(['outTradeNo' => $data['req_seq_id']]);
                if ($entity) {
                    if ($data['resp_code'] === '00000000') {
                        if($entity->getStatus() == Invoice::STATUS_PENDING
                           || $entity->getStatus() == Invoice::STATUS_CANCELLED) {
                            $entity->setTransactionId($data['out_trans_id']);
                            $entity->setPaidTime(new \DateTime());
                            $invoiceService->update($entity, ['status' => Invoice::STATUS_PAID]);
                        }
                    } else {
                        // On failure: clear extraData and retry payment
                        $entity->setExtraData([]);
                        $invoiceService->update($entity);
                        $invoiceService->pay($entity);
                    }
                }
            } finally {
                $lock->release();
            }
            return new Response($receivedOrderId);
        }
        return new Response('ERROR');
    }

    // --- refund ---
    function refund(Invoice $invoice, $amount)
    {
        $generateCode = function($length = 8) {
            return strtoupper(substr(md5(microtime().rand()), 5, $length));
        };
        $extraData = json_decode(json_encode($invoice->getExtraData()), true);
        $invoiceOutTradeNo = $generateCode(20);

        $data = [
            'req_date' => (new \DateTime())->format('Ymd'),
            'req_seq_id' => $invoiceOutTradeNo,
            'huifu_id' => $this->config['sys_id'],
            'ord_amt' => $amount,
            'org_req_date' => $invoice->getCreatedTime()->format('Ymd'),
            'org_req_seq_id' => $invoice->getOutTradeNo(),
        ];

        ksort($data);
        $sign = self::sha_with_rsa_sign(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->config['rsa_merch_private_key']
        );

        $postData = ['sys_id' => $this->config['sys_id'], 'product_id' => $this->config['product_id'], 'data' => $data, 'sign' => $sign];

        $curl = new Curl();
        $curl->setHeader('Content-Type', 'application/json; charset=utf-8');
        $curl->post($this->refundApiUrl, json_encode($postData));
        $refund = json_decode($curl->getResponse(), true)['data'];

        if($refund['resp_code'] == '00000100') {
            $extraData['refund'] = $refund;
            $invoice->setExtraData($extraData);
            $invoiceService->update($invoice, ['status' => Invoice::STATUS_REFUNDED]);
            return true;
        }
        throw new ValidatorException($refund['resp_desc']);
    }

    // --- Signing (same as SDK) ---
    static public function sha_with_rsa_sign($data, $rsaPrivateKey, $alg=OPENSSL_ALGO_SHA256){
        $key = "-----BEGIN PRIVATE KEY-----\n".wordwrap($rsaPrivateKey, 64, "\n", true)."\n-----END PRIVATE KEY-----";
        openssl_sign($data, $signature, $key, $alg);
        return base64_encode($signature);
    }

    static public function verifySign($signature, $data, $rsaPublicKey, $alg=OPENSSL_ALGO_SHA256){
        $key = "-----BEGIN PUBLIC KEY-----\n".wordwrap($rsaPublicKey, 64, "\n", true)."\n-----END PUBLIC KEY-----";
        return openssl_verify($data, base64_decode($signature), $key, $alg);
    }
}
```

### 5.3 HuifuPosPayStrategy (POS variant)

```php
// File: MainBundle/Payment/HuifuPosPayStrategy.php (168 lines)
namespace MainBundle\Payment;

class HuifuPosPayStrategy implements PayStrategy
{
    const PAYMENT = 'huifu-pos';

    function pay(Invoice $invoice) {
        throw new AccessDeniedException();  // Not Web-initiated
    }

    function refund(Invoice $invoice, $amount) {
        throw new AccessDeniedException();
    }

    function notify(): Response
    {
        // Key difference: uses rsa_async_public_key (separate from normal payment key!)
        $rsaPubKey = $this->container->getParameter('huifu.payment')['rsa_async_public_key'];

        parse_str($request->getContent(), $content);

        if($content['resp_code'] === '00000000') {
            if (!HuifuPayStrategy::verifySign($content['sign'], $content['resp_data'], $rsaPubKey))
                throw new ValidatorException('Checksum failure');

            $data = json_decode($content['resp_data'], true);

            // Look up POS device by terminalId (devs_id)
            $posDevice = $huifuPosDeviceService->get(['terminalId' => $data['devs_id']]);
            $store = $posDevice->getStore();
            $targetUser = $posDevice->getTargetUser() ?? $store->getUser();

            // Create Deposit entity, link Invoice, mark PAID
            // Uses semaphore lock RECV_ORD_ID_{req_seq_id}

            return new Response("RECV_ORD_ID_{$data['req_seq_id']}");
        }
        return new Response('ERROR');
    }
}
```

### 5.4 InvoiceListener — close order on cancel

```php
// File: HuifuBundle/EventListener/InvoiceListener.php
class InvoiceListener extends EntityListener
{
    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getObject();
        $changeArray = $args->getEntityChangeSet();

        if ($entity instanceof Invoice
            && $entity->getPayment() == Invoice::PAYMENT_HUIFU
            && array_key_exists('status', $changeArray)
        ) {
            [$before, $after] = $changeArray['status'];

            // When PENDING → CANCELLED: close the Huifu order
            if($before == Invoice::STATUS_PENDING && $after == Invoice::STATUS_CANCELLED) {
                $data = [
                    'req_date' => (new \DateTime())->format('Ymd'),
                    'req_seq_id' => UUID::v4c(),
                    'huifu_id' => $config['sys_id'],
                    'org_req_date' => $extraData['payment']['req_date'],
                    'org_req_seq_id' => $entity->getOutTradeNo(),
                ];
                ksort($data);
                $sign = HuifuPayStrategy::sha_with_rsa_sign(...);
                // POST to https://api.huifu.com/v2/trade/payment/scanpay/close
            }
        }
    }
}
```

### 5.5 HuifuAccountService — transfer and withdraw

```php
// File: HuifuBundle/Service/HuifuAccountService.php (334 lines)
namespace HuifuBundle\Service;

final class HuifuAccountService extends BaseService
{
    // balance — query sub-merchant account balance
    public function balance(HuifuAccount $entity) { ... }

    // sync — pull account info from Huifu, update entity
    public function sync(HuifuAccount $entity, bool $persist = true) {
        // Uses V2UserBasicdataQueryRequest
        // populates: indvBaseInfo, entBaseInfo, settleConfig, cardInfo, qryCashConfigList, qryCashCardInfoList
    }

    // userBusinessModify — modify settlement, card info
    public function userBusinessModify(HuifuAccount $account, array $data = []) { ... }

    // transfer — sub-merchant to sub-merchant transfer
    // POST /v2/trade/acctpayment/pay
    // Uses acctSplitBunch with div_amt + huifu_id
    public function transfer(HuifuAccount $from, HuifuAccount $to, float $amount) {
        $amount = sprintf('%.2f', $amount);
        $request = new V2TradeAcctpaymentPayRequest();
        $request->setOutHuifuId($from->getHuifuId());
        $request->setOrdAmt($amount);
        $request->setAcctSplitBunch(json_encode([
            'acct_infos' => [[
                'div_amt' => $amount,
                'huifu_id' => $to->getHuifuId(),
            ]]
        ]));
    }

    // withdraw — transfer + settlement enchashment
    // 1. transfer from main account to sub account
    // 2. POST /v2/trade/settlement/enchashment (with token_no, cashAmt, intoAcctDateType="DM")
    // 3. On failure: reverse the transfer
    public function withdraw(HuifuAccount $account, float $amount) { ... }
}
```

---

## 6. InvoiceClose Event (close unpaid order)

When Invoice status changes PENDING → CANCELLED and `payment == 'huifu'`:

```php
// POST https://api.huifu.com/v2/trade/payment/scanpay/close
$data = [
    'req_date' => (new \DateTime())->format('Ymd'),
    'req_seq_id' => UUID::v4c(),
    'huifu_id' => $config['sys_id'],
    'org_req_date' => $extraData['payment']['req_date'],
    'org_req_seq_id' => $entity->getOutTradeNo(),
];
// ksort + sign + POST
```

---

## 7. Configuration Shape

```yaml
huifu.payment:
    sys_id: "6666000135263684"        # Merchant system ID
    product_id: "YMFZS"               # Product ID (default for yunmafu)
    rsa_merch_private_key: "..."      # Raw base64 (no PEM headers)
    rsa_huifu_public_key: "..."       # Raw base64 (no PEM headers)
    rsa_async_public_key: "..."       # (optional) For POS async notifications
```

---

## 8. Integration Notes for crud-skeleton

### 8.1 Amount conversion

| Direction | Legacy | New Module |
|-----------|--------|------------|
| Stored | decimal string | int (cents) |
| → Huifu | `"10.00"` (yuan string) | `sprintf('%.2f', $cents / 100)` |
| ← Huifu | `"10.00"` string | `(int) round(floatval($yuan) * 100)` |

### 8.2 Status mapping

| Legacy | New |
|--------|-----|
| `Invoice::STATUS_PENDING` (0) | `'pending'` |
| `Invoice::STATUS_PAID` (1) | `'paid'` |
| `Invoice::STATUS_CANCELLED` (-2) | `'cancelled'` |
| `Invoice::STATUS_FAILED` (-2) | `'failed'` |
| `Invoice::STATUS_REFUNDED` (-1) | `'refunded'` |

### 8.3 Gateway interface mapping

| Legacy `PayStrategy` | New `PaymentGatewayInterface` |
|---------------------|------------------------------|
| `pay(Invoice)` → array\|false | `pay(Invoice, array $options)` → `PaymentResult` |
| `notify()` → mixed | `notify(Request)` → `PaymentNotifyResult` |
| `refund(Invoice, $amount)` → bool | `refund(Invoice, int, string, array)` → `PaymentRefundResult` |
| — | `getNotifySuccessResponse()` → `Response` |

### 8.4 Notification response

Legacy returns plain text `RECV_ORD_ID_{req_seq_id}`. New:

```php
return new Response('RECV_ORD_ID_' . $result->outTradeNo, 200,
    ['Content-Type' => 'text/plain']);
```

### 8.5 Notification body parsing (CRITICAL)

Huifu callbacks use `application/x-www-form-urlencoded`, NOT JSON:

```php
parse_str($request->getContent(), $content);
// $content['resp_code'], $content['resp_data'] (JSON string), $content['sign']
```

### 8.6 Key idempotency pattern

```php
// Legacy uses Symfony Lock + SemaphoreStore
$receivedOrderId = "RECV_ORD_ID_{$data['req_seq_id']}";
$factory = new Factory(new SemaphoreStore());
$lock = $factory->createLock($receivedOrderId, 30);
```

New module already has idempotency in `InvoiceService::markPaid()` (checks status before workflow transition).

### 8.7 No SDK needed

Core is simple:
1. `ksort($data)`
2. `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`
3. `openssl_sign($json, $sig, $privateKey, OPENSSL_ALGO_SHA256)`
4. `base64_encode($sig)`
5. POST JSON envelope to `https://api.huifu.com/v2/trade/payment/jspay`

A standalone `HuifuSigner` helper replaces the SDK completely.

### 8.8 Recommended file structure

```text
src/Payment/Service/Gateway/Huifu/
├── HuifuGateway.php      — implements PaymentGatewayInterface
├── HuifuPosGateway.php   — POS variant (future)
├── HuifuClient.php       — HTTP client (wrap Symfony HttpClient)
├── HuifuSigner.php       — RSA-SHA256 sign + verify
└── HuifuNotifyParser.php — parse application/x-www-form-urlencoded callbacks
```

---

## 9. ALL Legacy API Endpoints (for reference)

From SDK function codes (`FunctionCodeEnum`):

| Function Code | URL | Purpose |
|---|---|---|
| `V2_TRADE_PAYMENT_JSPAY` | `/v2/trade/payment/jspay` | Unified order |
| `V2_TRADE_PAYMENT_MICROPAY` | `/v2/trade/payment/micropay` | Barcode pay |
| `V2_TRADE_PAYMENT_SCANPAY_QUERY` | `/v2/trade/payment/scanpay/query` | Query order |
| `V2_TRADE_PAYMENT_SCANPAY_CLOSE` | `/v2/trade/payment/scanpay/close` | Close order |
| `V2_TRADE_PAYMENT_SCANPAY_REFUND` | `/v2/trade/payment/scanpay/refund` | Refund |
| `V2_TRADE_PAYMENT_SCANPAY_REFUNDQUERY` | `/v2/trade/payment/scanpay/refundquery` | Refund query |
| `V2_TRADE_ACCTPAYMENT_PAY` | `/v2/trade/acctpayment/pay` | Sub-merchant transfer |
| `V2_TRADE_ACCTPAYMENT_BALANCE_QUERY`| `/v2/trade/acctpayment/balance/query` | Balance query |
| `V2_TRADE_SETTLEMENT_ENCHASHMENT` | `/v2/trade/settlement/enchashment` | Withdraw to bank card |
| `V2_TRADE_ONLINEPAYMENT_QUICKPAY_FRONTPAY` | `/v2/trade/onlinepayment/quickpay/frontpay` | H5/quick pay |
| `V2_QUICKBUCKLE_ONEKEY_CARDBIND` | — | Card binding |

Sub-merchant account / POS / withdraw APIs are for a future `Payment\Provider\Huifu\` submodule, NOT the payment gateway.
