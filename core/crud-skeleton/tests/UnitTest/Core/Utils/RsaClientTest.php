<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\RsaClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Core\Utils\RsaClient.
 *
 * A fresh RSA key pair is generated with openssl_pkey_new() (no openssl CLI
 * required) and injected via the public properties so no src/ refactor is
 * needed.
 *
 * Lines that remain uncovered on purpose:
 *  - src/Core/Utils/RsaClient.php:55 and :104 call openssl_free_key(), which is
 *    deprecated since PHP 8.0 and triggers a deprecation; the suite is
 *    configured with failOnDeprecation, so those branches are not exercised.
 *  - The $encryptionOk === false / $decryptionOK === false branches in the
 *    encrypt/decrypt helpers are defensive dead code (the key length is always
 *    validated before those calls, so chunk size always fits the modulus).
 *
 * @see docs/issues/coverage-2026-08-09/core-utils-di.md
 */
final class RsaClientTest extends TestCase
{
    /** @var string */
    private static string $privatePem2048;

    /** @var string */
    private static string $publicPem2048;

    /** @var string */
    private static string $privatePem1024;

    /** @var string */
    private static string $publicPem1024;

    private RsaClient $client;

    public static function setUpBeforeClass(): void
    {
        self::$privatePem2048 = self::generatePrivateKey(2048);
        self::$publicPem2048 = self::derivePublicKey(self::$privatePem2048);
        self::$privatePem1024 = self::generatePrivateKey(1024);
        self::$publicPem1024 = self::derivePublicKey(self::$privatePem1024);
    }

    protected function setUp(): void
    {
        $this->client = new RsaClient();
    }

    public function testRsaSignWithPemPrivateKey(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;

        $sign = $this->client->rsaSign(['a' => '1', 'b' => '2']);

        self::assertNotSame('', $sign);
        self::assertIsString($sign);
        self::assertNotFalse(base64_decode($sign, true));
    }

    public function testRsaSignWithRawBase64KeyGetsWrapped(): void
    {
        $raw = self::stripPemHeaders(self::$privatePem2048);
        $this->client->rsaPrivateKey = $raw;

        $sign = $this->client->rsaSign(['foo' => 'bar']);

        self::assertNotSame('', $sign);
    }

    public function testSignReturnsEmptyWhenPrivateKeyUnavailable(): void
    {
        $this->client->rsaPrivateKeyFilePath = '/nonexistent/private.key';

        // file_get_contents() on a missing file raises E_WARNING from src.
        self::assertSame('', $this->withSuppressedWarnings(fn (): string => $this->client->sign('payload')));
    }

    public function testRsaVerifySignSucceedsWithMatchingSignature(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;
        $this->client->rsaPublicKey = self::$publicPem2048;

        $params = ['name' => 'php', 'version' => '8.5'];
        $sign = $this->client->rsaSign($params);

        self::assertTrue($this->client->rsaVerifySign($params, $sign));
    }

    public function testRsaVerifySignFailsOnTamperedPayload(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;
        $this->client->rsaPublicKey = self::$publicPem2048;

        $sign = $this->client->rsaSign(['name' => 'php']);

        self::assertFalse($this->client->rsaVerifySign(['name' => 'php2'], $sign));
        self::assertFalse($this->client->rsaVerifySign(['name' => 'php'], 'garbage'));
    }

    public function testVerifySignReturnsFalseWhenPublicKeyUnavailable(): void
    {
        $this->client->rsaPublicKeyFilePath = '/nonexistent/public.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): bool => $this->client->verifySign('data', 'sig')));
    }

    public function testGetSignContentSortsKeysAndSkipsEmptyValues(): void
    {
        $result = $this->client->getSignContent([
            'b' => '2',
            'a' => '1',
            'empty' => null,
            'space' => '   ',
            'zero' => '0',
        ]);

        self::assertSame('a=1&b=2&zero=0', $result);
    }

    public function testGetSignContentSingleParamHasNoAmpersand(): void
    {
        self::assertSame('x=y', $this->client->getSignContent(['x' => 'y']));
    }

    public function testGetSignContentEmptyParams(): void
    {
        self::assertSame('', $this->client->getSignContent([]));
    }

    public function testGetPrivateKeyReturnsInlinePemAsIs(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;

        self::assertSame(self::$privatePem2048, $this->client->getPrivateKey());
    }

    public function testGetPrivateKeyWrapsRawKeyWithHeaders(): void
    {
        $this->client->rsaPrivateKey = self::stripPemHeaders(self::$privatePem2048);

        $key = $this->client->getPrivateKey();

        self::assertIsString($key);
        self::assertStringStartsWith('-----BEGIN RSA PRIVATE KEY-----', $key);
        self::assertStringEndsWith('-----END RSA PRIVATE KEY-----', $key);
    }

    public function testGetPrivateKeyFromFile(): void
    {
        $file = $this->writeTempFile(self::$privatePem2048);
        $this->client->rsaPrivateKeyFilePath = $file;

        $key = $this->client->getPrivateKey();

        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
    }

    public function testGetPrivateKeyFromMissingFileReturnsFalse(): void
    {
        $this->client->rsaPrivateKeyFilePath = '/nonexistent/private.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): mixed => $this->client->getPrivateKey()));
    }

    public function testGetPublicKeyReturnsInlinePemAsIs(): void
    {
        $this->client->rsaPublicKey = self::$publicPem2048;

        self::assertSame(self::$publicPem2048, $this->client->getPublicKey());
    }

    public function testGetPublicKeyWrapsRawKeyWithHeaders(): void
    {
        $this->client->rsaPublicKey = self::stripPemHeaders(self::$publicPem2048);

        $key = $this->client->getPublicKey();

        self::assertIsString($key);
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $key);
        self::assertStringEndsWith('-----END PUBLIC KEY-----', $key);
    }

    public function testGetPublicKeyFromFile(): void
    {
        $file = $this->writeTempFile(self::$publicPem2048);
        $this->client->rsaPublicKeyFilePath = $file;

        $key = $this->client->getPublicKey();

        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
    }

    public function testGetPublicKeyFromMissingFileReturnsFalse(): void
    {
        $this->client->rsaPublicKeyFilePath = '/nonexistent/public.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): mixed => $this->client->getPublicKey()));
    }

    public function testGetPrivateKenLen(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;

        self::assertSame(2048, $this->client->getPrivateKenLen());
    }

    public function testGetPrivateKenLenReturnsFalseForMissingFile(): void
    {
        $this->client->rsaPrivateKeyFilePath = '/nonexistent/private.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): int|false => $this->client->getPrivateKenLen()));
    }

    public function testGetPrivateKenLenReturnsFalseForInvalidKey(): void
    {
        $this->client->rsaPrivateKey = 'not-a-real-key';

        self::assertFalse($this->client->getPrivateKenLen());
    }

    public function testGetPublicKenLen(): void
    {
        $this->client->rsaPublicKey = self::$publicPem1024;

        self::assertSame(1024, $this->client->getPublicKenLen());
    }

    public function testGetPublicKenLenReturnsFalseForInvalidKey(): void
    {
        $this->client->rsaPublicKey = 'not-a-real-key';

        self::assertFalse($this->client->getPublicKenLen());
    }

    public function testPrivateEncryptAndPublicDecryptRoundTrip(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;
        $this->client->rsaPublicKey = self::$publicPem2048;

        $data = 'The quick brown fox jumps over the lazy dog 0123456789';

        $encrypted = $this->client->privateEncryptRsa($data);
        self::assertIsString($encrypted);

        self::assertSame($data, $this->client->publicDecryptRsa($encrypted));
    }

    public function testPublicEncryptAndPrivateDecryptRoundTrip(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;
        $this->client->rsaPublicKey = self::$publicPem2048;

        $data = 'confidential payload for private key holder';

        $encrypted = $this->client->publicEncryptRsa($data);
        self::assertIsString($encrypted);

        self::assertSame($data, $this->client->privateDecryptRsa($encrypted));
    }

    public function testPrivateEncryptRejectsNonString(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;

        self::assertFalse($this->client->privateEncryptRsa(['not', 'a', 'string']));
    }

    public function testPublicEncryptRejectsNonString(): void
    {
        $this->client->rsaPublicKey = self::$publicPem2048;

        self::assertFalse($this->client->publicEncryptRsa(12345));
    }

    public function testPrivateDecryptRejectsNonString(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;

        self::assertFalse($this->client->privateDecryptRsa(42));
    }

    public function testPublicDecryptRejectsNonString(): void
    {
        $this->client->rsaPublicKey = self::$publicPem2048;

        self::assertFalse($this->client->publicDecryptRsa(null));
    }

    public function testPrivateEncryptReturnsFalseWhenKeyUnavailable(): void
    {
        $this->client->rsaPrivateKeyFilePath = '/nonexistent/private.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): string|false => $this->client->privateEncryptRsa('data')));
    }

    public function testPublicEncryptReturnsFalseWhenKeyUnavailable(): void
    {
        $this->client->rsaPublicKeyFilePath = '/nonexistent/public.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): string|false => $this->client->publicEncryptRsa('data')));
    }

    public function testPublicDecryptReturnsFalseOnMismatchedCiphertext(): void
    {
        $this->client->rsaPrivateKey = self::$privatePem2048;
        $this->client->rsaPublicKey = self::$publicPem1024;

        $encrypted = $this->client->privateEncryptRsa('secret data here');

        self::assertIsString($encrypted);
        self::assertFalse($this->client->publicDecryptRsa($encrypted));
    }

    public function testPrivateDecryptReturnsFalseWhenKeyUnavailable(): void
    {
        $this->client->rsaPrivateKeyFilePath = '/nonexistent/private.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): string|false => $this->client->privateDecryptRsa(base64_encode('data'))));
    }

    public function testPublicDecryptReturnsFalseWhenKeyUnavailable(): void
    {
        $this->client->rsaPublicKeyFilePath = '/nonexistent/public.key';

        self::assertFalse($this->withSuppressedWarnings(fn (): string|false => $this->client->publicDecryptRsa(base64_encode('data'))));
    }

    private static function generatePrivateKey(int $bits): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $exported = '';
        self::assertTrue(openssl_pkey_export($key, $exported));

        return $exported;
    }

    private static function derivePublicKey(string $privatePem): string
    {
        $key = openssl_pkey_get_private($privatePem);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);

        return $details['key'];
    }

    private static function stripPemHeaders(string $pem): string
    {
        $lines = preg_split('/\R/', trim($pem));
        $lines = array_filter($lines, static fn (string $line): bool => strpos($line, '-----') === false);

        return implode('', $lines);
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rsa_test_');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    /**
     * Runs $fn while PHP E_WARNING reporting is disabled so that the E_WARNING
     * raised by file_get_contents() on a missing key file inside src/ does not
     * trip failOnWarning.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withSuppressedWarnings(callable $fn): mixed
    {
        $previous = error_reporting();
        error_reporting(0);
        try {
            return $fn();
        } finally {
            error_reporting($previous);
        }
    }
}
