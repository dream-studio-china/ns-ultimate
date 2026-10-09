<?php
namespace App\Core\Utils;
/**
 * Created by PhpStorm.
 * User: tr
 * Date: 2020/5/27
 * Time: 10:48
 */
class RsaClient
{
    // 私钥文件路径
    public mixed $rsaPrivateKeyFilePath = null;

    // 私钥值
    public mixed $rsaPrivateKey = null;

    // 公钥文件路径
    public mixed $rsaPublicKeyFilePath = null;

    // 公钥值
    public mixed $rsaPublicKey = null;

    /**
     * 生成签名
     * @param array<array-key, mixed> $params
     */
    public function rsaSign(array $params): string
    {
        return $this->sign($this->getSignContent($params));
    }

    /**
     * 验证签名
     * @param array<array-key, mixed> $params
     */
    public function rsaVerifySign(array $params, mixed $sign): bool
    {
        return $this->verifySign($this->getSignContent($params), $sign);
    }

    /**
     * 通过私钥生成签名
     * @param $data
     */
    public function sign(mixed $data): string
    {
        $res = $this->getPrivateKey();

        if (!$res) return "";

        openssl_sign((string) $data, $sign, $res, OPENSSL_ALGO_MD5);

        if (!$this->checkEmpty($this->rsaPrivateKeyFilePath)) {
            if ($res instanceof \OpenSSLAsymmetricKey) {
                openssl_free_key($res);
            }
        }
        $sign = base64_encode($sign);
        return $sign;
    }

    /**
     * 获取签名字符串
     * @param array<array-key, mixed> $params
     */
    public function getSignContent(array $params): string
    {
        ksort($params);
        reset($params);
        $stringToBeSigned = "";
        $i = 0;
        foreach ($params as $k => $v) {
            if (false === $this->checkEmpty($v)) {
                if ($i == 0) {
                    $stringToBeSigned .= "$k" . "=" . "$v";
                } else {
                    $stringToBeSigned .= "&" . "$k" . "=" . "$v";
                }
                $i++;
            }
        }

        unset ($k, $v);
        return $stringToBeSigned;
    }

    /**
     * 公钥验证签名
     * @param $data
     * @param $sign
     */
    public function verifySign(mixed $data, mixed $sign) : bool
    {
        $res = $this->getPublicKey();

        if (!$res) return false;

        //调用openssl内置方法验签，返回bool值
        $result = (openssl_verify((string) $data, base64_decode((string) $sign), $res, OPENSSL_ALGO_MD5) === 1);

        if (!$this->checkEmpty($this->rsaPublicKeyFilePath)) {
            //释放资源
            if ($res instanceof \OpenSSLAsymmetricKey) {
                openssl_free_key($res);
            }
        }

        return $result;
    }

    /**
     * 校验$value是否非空
     * @param $value
     */
    protected function checkEmpty(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /**
     * @return false|\OpenSSLAsymmetricKey|string
     */
    public function getPrivateKey(): \OpenSSLAsymmetricKey|string|false
    {
        if ($this->checkEmpty($this->rsaPrivateKeyFilePath)) {
            $priKey = $this->rsaPrivateKey;
            if (strpos($priKey,'-----') !== false) {
                $res = $priKey;
            }else{
				$priKey = str_replace(array("\n", "\r"), array("", ""), $priKey);
                $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
                    wordwrap($priKey, 64, "\n", true) .
                    "\n-----END RSA PRIVATE KEY-----";
            }
        } else {
            $priKey = file_get_contents((string) $this->rsaPrivateKeyFilePath);
            if ($priKey === false) {
                return false;
            }
            $res = openssl_get_privatekey($priKey);
        }
        return $res;
    }

    /**
     * @return false|\OpenSSLAsymmetricKey|string
     */
    public function getPublicKey(): \OpenSSLAsymmetricKey|string|false
    {
        if ($this->checkEmpty($this->rsaPublicKeyFilePath)) {

            $pubKey = $this->rsaPublicKey;
            if (strpos($pubKey,'-----') !== false) {
                $res = $pubKey;
            }else{
				$pubKey = str_replace(array("\n", "\r"), array("", ""), $pubKey);
                $res = "-----BEGIN PUBLIC KEY-----\n" .
                    wordwrap($pubKey, 64, "\n", true) .
                    "\n-----END PUBLIC KEY-----";
            }

        } else {
            //读取公钥文件
            $pubKey = file_get_contents((string) $this->rsaPublicKeyFilePath);
            if ($pubKey === false) {
                return false;
            }
            //转换为openssl格式密钥
            $res = openssl_get_publickey($pubKey);
        }
        return $res;
    }

    /**
     * 返回私钥的长度 512 1024 2408
     * @return int|false
     */
    public function getPrivateKenLen(): int|false
    {
        $key = $this->getPrivateKey();
        if ($key === false) {
            return false;
        }
        $pubId = openssl_get_privatekey($key);
        if ($pubId === false) {
            return false;
        }

        $details = openssl_pkey_get_details($pubId);

        return $details === false ? false : $details['bits'];
    }
    /**
     * 返回公钥的长度 512 1024 2408
     * @return int|false
     */
    public function getPublicKenLen(): int|false
    {
        $key = $this->getPublicKey();
        if ($key === false) {
            return false;
        }
        $pubId = openssl_get_publickey($key);
        if ($pubId === false) {
            return false;
        }

        $details = openssl_pkey_get_details($pubId);

        return $details === false ? false : $details['bits'];
    }
    /**
     * RSA私钥加密数据
     * @param $plainData
     * @return string|false
     */
    public function privateEncryptRsa(mixed $plainData = ''): string|false
    {
        if (!is_string($plainData)) {
            return false;
        }
        $encrypted = '';

        $keyLength = $this->getPrivateKenLen();
        if ($keyLength === false) {
            return false;
        }
        $partLen = intdiv($keyLength, 8) - 11;
        if ($partLen < 1) {
            return false;
        }

        $plainData = str_split($plainData, $partLen);

        $privatePEMKey = $this->getPrivateKey();
        if ($privatePEMKey === false) {
            return false;
        }

        foreach ($plainData as $chunk) {
            $partialEncrypted = '';

            //using for example OPENSSL_PKCS1_PADDING as padding
            $encryptionOk = openssl_private_encrypt($chunk, $partialEncrypted, $privatePEMKey, OPENSSL_PKCS1_PADDING);

            if ($encryptionOk === false) {
                return false;
            }//also you can return and error. If too big this will be false
            $encrypted .= $partialEncrypted;
        }
        return base64_encode($encrypted);//encoding the whole binary String as MIME base 64
    }
    /**
     * RSA公钥加密数据
     * @param $plainData
     * @return string|false
     */
    public function publicEncryptRsa(mixed $plainData = ''): string|false
    {
        if (!is_string($plainData)) {
            return false;
        }

        $encrypted = '';

        $keyLength = $this->getPublicKenLen();
        if ($keyLength === false) {
            return false;
        }
        $partLen = intdiv($keyLength, 8) - 11;
        if ($partLen < 1) {
            return false;
        }

        $plainData = str_split($plainData, $partLen);

        $publicPEMKey = $this->getPublicKey();
        if ($publicPEMKey === false) {
            return false;
        }

        foreach ($plainData as $chunk) {
            $partialEncrypted = '';

            //using for example OPENSSL_PKCS1_PADDING as padding
            $encryptionOk = openssl_public_encrypt($chunk, $partialEncrypted, $publicPEMKey, OPENSSL_PKCS1_PADDING);

            if ($encryptionOk === false) {
                return false;
            }//also you can return and error. If too big this will be false
            $encrypted .= $partialEncrypted;
        }
        return base64_encode($encrypted);//encoding the whole binary String as MIME base 64
    }
    /**
     * 私钥解密数据
     * @param $data
     * @return string|false
     */
    public function privateDecryptRsa(mixed $data = ''): string|false
    {
        if (!is_string($data)) {
            return false;
        }
        $decrypted = '';

        $keyLength = $this->getPrivateKenLen();
        if ($keyLength === false) {
            return false;
        }
        $partLen = intdiv($keyLength, 8);
        if ($partLen < 1) {
            return false;
        }
        //decode must be done before spliting for getting the binary String
        $data = str_split(base64_decode($data), $partLen);

        $privatePEMKey = $this->getPrivateKey();
        if ($privatePEMKey === false) {
            return false;
        }

        foreach ($data as $chunk) {
            $partial = '';

            //be sure to match padding
            $decryptionOK = openssl_private_decrypt($chunk, $partial, $privatePEMKey, OPENSSL_PKCS1_PADDING);

            if ($decryptionOK === false) {
                return false;
            }//here also processed errors in decryption. If too big this will be false
            $decrypted .= $partial;
        }
        return $decrypted;
    }
    /**
     * 公钥解密数据
     * @param $data
     * @return string|false
     */
    public function publicDecryptRsa(mixed $data = ''): string|false
    {
        if (!is_string($data)) {
            return false;
        }

        $decrypted = '';

        $keyLength = $this->getPublicKenLen();
        if ($keyLength === false) {
            return false;
        }
        $partLen = intdiv($keyLength, 8);
        if ($partLen < 1) {
            return false;
        }
        //decode must be done before spliting for getting the binary String
        $data = str_split(base64_decode($data), $partLen);

        $publicPEMKey = $this->getPublicKey();
        if ($publicPEMKey === false) {
            return false;
        }

        foreach ($data as $chunk) {
            $partial = '';

            //be sure to match padding
            $decryptionOK = openssl_public_decrypt($chunk, $partial, $publicPEMKey, OPENSSL_PKCS1_PADDING);

            if ($decryptionOK === false) {
                return false;
            }//here also processed errors in decryption. If too big this will be false
            $decrypted .= $partial;
        }
        return $decrypted;
    }
}
