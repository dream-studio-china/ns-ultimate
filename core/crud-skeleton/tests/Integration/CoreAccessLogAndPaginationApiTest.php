<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Common\Entity\Category;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Two concerns, one file to share the schema bootstrap:
 *
 *  A) AccessLogListener (task 6): verifies real log lines are produced for
 *     write requests via the "access" monolog channel (var/log/access.log).
 *     The test handler approach is not used — monolog test config streams the
 *     access channel to a file, so we assert on the file directly.
 *
 *  B) RestController::pagination() edge cases (task 7) through the HTTP kernel:
 *     page beyond range, limit=0, negative page, non-int coercion.
 */
final class CoreAccessLogAndPaginationApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const ACCESS_LOG = __DIR__ . '/../../var/log/access.log';

    private static function accessLogPath(): string
    {
        return getenv('TEST_ACCESS_LOG') ?: self::ACCESS_LOG;
    }

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Common\\Entity\\Category c')->execute();
        foreach (['Alpha', 'Beta', 'Gamma'] as $i => $name) {
            $category = new Category($name, 'slug-' . $i);
            $category->setSortOrder($i);
            $em->persist($category);
        }
        $em->flush();
        self::ensureKernelShutdown();
    }

    private static function truncateAccessLog(): void
    {
        file_put_contents(self::accessLogPath(), '');
        clearstatcache();
    }

    public function testAccessLogListenerWritesWriteRequestLine(): void
    {
        self::truncateAccessLog();
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'LogCat', 'slug' => 'log-cat'], JSON_THROW_ON_ERROR));

        self::assertSame(201, $client->getResponse()->getStatusCode());

        $content = (string) file_get_contents(self::accessLogPath());
        self::assertNotSame('', $content, 'access.log should contain a line');
        self::assertStringContainsString('POST /api/v1/manage/categories | 201', $content);
        self::assertStringContainsString('REQ: {"name":"LogCat","slug":"log-cat"}', $content);
        self::assertStringContainsString('RES: {"data":{"id":', $content);
        self::assertStringContainsString('@testauth#', $content);
    }

    public function testAccessLogListenerDoesNotLogGetRequests(): void
    {
        self::truncateAccessLog();
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?limit=5');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        usleep(200_000);
        $content = (string) file_get_contents(self::accessLogPath());
        self::assertSame('', $content, 'GET requests must not be written to access.log');
    }

    public function testAccessLogListenerHidesAuthBodies(): void
    {
        self::truncateAccessLog();
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'HiddenBody', 'slug' => 'hidden-body'], JSON_THROW_ON_ERROR));

        $content = (string) file_get_contents(self::accessLogPath());
        self::assertStringNotContainsString('(body hidden)', $content);
        self::assertStringContainsString('{"name":"HiddenBody","slug":"hidden-body"}', $content);
    }

    // ---- RestController::pagination() edge cases ----

    public function testPageBeyondRangeIsSafe(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?page=999&limit=2');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
        self::assertSame(3, $body['paginator']['total']);
        self::assertSame(2, $body['paginator']['pages']);
        self::assertSame(999, $body['paginator']['page']);
        self::assertTrue($body['paginator']['has_previous']);
        self::assertFalse($body['paginator']['has_next']);
    }

    public function testNegativePageIsCoercedToOne(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?page=-5&limit=2');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame(['Alpha', 'Beta'], array_column($body['data'], 'name'));
        self::assertSame(1, $body['paginator']['page']);
    }

    public function testZeroLimitIsCoercedToOne(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?limit=0');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['data']);
        self::assertSame(1, $body['paginator']['limit']);
        self::assertSame(3, $body['paginator']['pages']);
    }

    public function testPaginationFieldsAreComplete(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?page=2&limit=2');

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $paginator = $body['paginator'];
        self::assertSame(['total', 'page', 'limit', 'pages', 'has_previous', 'has_next'], array_keys($paginator));
        self::assertSame(3, $paginator['total']);
        self::assertSame(2, $paginator['page']);
        self::assertSame(2, $paginator['limit']);
        self::assertSame(2, $paginator['pages']);
        self::assertTrue($paginator['has_previous']);
        self::assertFalse($paginator['has_next']);
        self::assertSame(['Gamma'], array_column($body['data'], 'name'));
    }
}
