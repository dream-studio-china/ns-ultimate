<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Unit;

use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use PHPUnit\Framework\TestCase;

final class HuifuSignerTest extends TestCase
{
    public function testSignedDataVerifiesAndTamperingFails(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        self::assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        $publicKey = $details['key'];
        $signer = new HuifuSigner();
        $data = ['req_seq_id' => 'HF1', 'trans_amt' => '10.00', 'huifu_id' => '6666000000000101'];

        $signature = $signer->sign($data, $privateKey);
        self::assertTrue($signer->verifyData($data, $signature, $publicKey));
        self::assertFalse($signer->verifyData(['req_seq_id' => 'HF2'] + $data, $signature, $publicKey));
    }

    public function testKeyBodyCanBeProvidedWithoutPemHeaders(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        self::assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $privateKey);
        self::assertIsString($body);

        $signer = new HuifuSigner();
        $signature = $signer->sign(['a' => 'b'], $body);
        self::assertTrue($signer->verifyData(['a' => 'b'], $signature, $details['key']));
    }
}
