<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Unit;

use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use PHPUnit\Framework\TestCase;

final class HuifuResponseParserTest extends TestCase
{
    public function testSignedResponsesVerifyAndUnsignedResponsesFailClosed(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        self::assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        $signer = new HuifuSigner();
        $parser = new HuifuResponseParser();
        $data = ['resp_code' => '00000100', 'req_seq_id' => 'HF1'];
        $response = ['data' => $data, 'sign' => $signer->sign($data, $privateKey)];

        $parser->verify($response, $signer, $details['key']);
        $this->expectException(\RuntimeException::class);
        $parser->verify(['data' => $data], $signer, $details['key']);
    }
}
