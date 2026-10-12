<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests;

use NsUltimate\Business\Wechat\Service\WechatSecretCipher;
use PHPUnit\Framework\TestCase;

final class WechatSecretCipherTest extends TestCase
{
    public function testCredentialsCanBeDecryptedButCiphertextDoesNotContainPlaintext(): void
    {
        $cipher = new WechatSecretCipher('test-only-key');
        $credentials = ['secret' => 'private-secret', 'private_key' => 'private-key-value'];
        $encrypted = $cipher->encrypt($credentials);

        self::assertSame($credentials, $cipher->decrypt($encrypted));
        self::assertStringNotContainsString('private-secret', $encrypted);
        self::assertStringNotContainsString('private-key-value', $encrypted);
    }

    public function testCiphertextCannotBeDecryptedWithAnotherKey(): void
    {
        $encrypted = (new WechatSecretCipher('test-key-one'))->encrypt(['secret' => 'value']);

        $this->expectException(\RuntimeException::class);
        (new WechatSecretCipher('test-key-two'))->decrypt($encrypted);
    }

    public function testMissingKeyDoesNotBreakNonWechatServicesButRejectsCredentialUse(): void
    {
        $cipher = new WechatSecretCipher('');

        $this->expectException(\InvalidArgumentException::class);
        $cipher->encrypt(['secret' => 'value']);
    }
}
