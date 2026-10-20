<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Service\PrinterCredentialCipher;
use PHPUnit\Framework\TestCase;

final class PrinterCredentialCipherTest extends TestCase
{
    public function testCredentialRoundTripsWithoutStoringPlaintext(): void
    {
        $cipher = new PrinterCredentialCipher('test-key');
        $encrypted = $cipher->encrypt('developer-secret');

        self::assertStringNotContainsString('developer-secret', $encrypted);
        self::assertSame(['userKey' => 'developer-secret'], $cipher->decrypt($encrypted));
    }

    public function testCredentialCannotBeDecryptedWithDifferentKey(): void
    {
        $encrypted = (new PrinterCredentialCipher('first-key'))->encrypt('developer-secret');

        $this->expectException(\RuntimeException::class);
        (new PrinterCredentialCipher('second-key'))->decrypt($encrypted);
    }

    public function testEmptyKeyIsRejectedWhenEncrypting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PrinterCredentialCipher(''))->encrypt('developer-secret');
    }
}
