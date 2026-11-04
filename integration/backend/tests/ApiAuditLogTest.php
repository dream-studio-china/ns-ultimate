<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;

/**
 * The audit channel must stay queryable in production instead of relying on
 * PHP-FPM stderr capture, and Huifu callback rejections must record their
 * reason rather than only the generic HTTP 400 body.
 */
final class ApiAuditLogTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testWriteRequestLogsActorBodyAndStatusWithoutCredentials(): void
    {
        $client = self::createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/v1/manage/users', [
            [
                'email' => 'audited-user@example.com',
                'username' => 'audited-user',
                'password' => 'P@ssw0rd-Secret',
            ],
        ], server: ['HTTP_X_REQUEST_ID' => 'audit-write-test']);
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $entry = $this->single('api_request', 'POST', '/api/v1/manage/users', 'audit-write-test');
        self::assertSame(201, $entry['status']);
        self::assertSame('/api/v1/manage/users', $entry['path']);
        self::assertIsInt($entry['durationMs']);
        self::assertIsString($entry['user']);
        self::assertStringContainsString('testauth', $entry['user']);
        self::assertIsArray($entry['requestHeaders']);
        foreach (array_keys($entry['requestHeaders']) as $header) {
            self::assertNotSame('authorization', strtolower((string) $header));
        }

        self::assertIsString($entry['requestBody']);
        self::assertStringContainsString('audited-user', $entry['requestBody']);
        self::assertStringContainsString('[redacted]', $entry['requestBody']);
        self::assertStringNotContainsString('P@ssw0rd-Secret', $entry['requestBody']);
        self::assertIsString($entry['responseBody']);
        self::assertStringContainsString('audited-user@example.com', $entry['responseBody']);
    }

    public function testReadRequestIsLoggedWithoutABody(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories', server: [
            'HTTP_X_AUDIT_TEST' => 'read',
            'HTTP_X_REQUEST_ID' => 'audit-read-test',
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $entry = $this->single('api_request', 'GET', '/api/v1/manage/categories', 'audit-read-test');
        self::assertSame(200, $entry['status']);
        self::assertNull($entry['requestBody']);
        self::assertNull($entry['responseBody']);
        self::assertSame('read', $entry['requestHeaders']['x-audit-test'] ?? null);
    }

    public function testHuifuCallbackRejectionRecordsTheVerificationReason(): void
    {
        $client = static::createClient();
        $attemptUuid = '11111111-1111-4111-8111-'.substr(bin2hex(random_bytes(6)), 0, 12);
        $path = '/api/payment/notify/huifu/'.$attemptUuid;
        $body = http_build_query([
            'resp_code' => '00000000',
            'resp_data' => '{"req_seq_id":"AUDIT-REQUEST"}',
            'sign' => 'not-a-real-signature',
        ]);
        $client->request(
            'POST',
            $path,
            server: [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X_REQUEST_ID' => 'audit-huifu-test',
            ],
            content: $body,
        );
        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('FAIL', $client->getResponse()->getContent());

        $rejection = $this->single('huifu_notification_rejected', 'POST', $path);
        self::assertSame($attemptUuid, $rejection['attemptUuid']);
        self::assertIsString($rejection['reason']);
        self::assertStringContainsString('not found', $rejection['reason']);

        $audit = $this->single('api_request', 'POST', $path, 'audit-huifu-test');
        self::assertSame(400, $audit['status']);
        self::assertIsString($audit['requestBody']);
        self::assertStringContainsString('not-a-real-signature', $audit['requestBody']);
        self::assertSame('FAIL', $audit['responseBody']);
    }

    /** @return array<string, mixed> */
    private function single(string $message, string $method, string $path, ?string $requestId = null): array
    {
        $matches = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => ($entry['message'] ?? null) === $message
                && ($entry['context']['method'] ?? null) === $method
                && ($entry['context']['path'] ?? null) === $path
                && ($requestId === null || ($entry['context']['requestId'] ?? null) === $requestId),
        ));
        self::assertCount(1, $matches, sprintf('Expected one %s audit entry for %s %s.', $message, $method, $path));

        $context = $matches[0]['context'];
        self::assertIsArray($context);

        return $context;
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        $entries = [];
        foreach (self::auditLogFiles() as $file) {
            $content = @file_get_contents($file);
            if (!is_string($content)) {
                continue;
            }
            foreach (preg_split('/\R/', trim($content)) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            }
        }

        self::assertNotSame([], $entries, 'The audit log was not written.');

        return $entries;
    }

    /** @return list<string> */
    private static function auditLogFiles(): array
    {
        $path = getenv('AUDIT_LOG_PATH');
        self::assertIsString($path);
        self::assertNotSame('', $path);
        // rotating_file appends the current date to the configured filename.
        $files = glob(
            pathinfo($path, PATHINFO_DIRNAME).'/'.pathinfo($path, PATHINFO_FILENAME).'-*.log',
        );

        return is_array($files) && $files !== [] ? array_values($files) : [$path];
    }
}
