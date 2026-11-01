<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Unit;

use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use PHPUnit\Framework\TestCase;

final class HuifuSecretCipherTest extends TestCase
{
    public function testCredentialsRoundTripAndFailWithAnotherKey(): void
    {
        $cipher = new HuifuSecretCipher('test-key-one');
        $encrypted = $cipher->encrypt(['merchant_private_key' => 'private', 'huifu_public_key' => 'public']);
        self::assertNotSame('private', $encrypted);
        self::assertSame(
            ['merchant_private_key' => 'private', 'huifu_public_key' => 'public'],
            $cipher->decrypt($encrypted),
        );

        $other = new HuifuSecretCipher('test-key-two');
        $this->expectException(\RuntimeException::class);
        $other->decrypt($encrypted);
    }

    public function testMissingConfigurationFailsClosed(): void
    {
        $cipher = new HuifuSecretCipher('');
        $this->expectException(\InvalidArgumentException::class);
        $cipher->encrypt(['merchant_private_key' => 'private']);
    }
}
