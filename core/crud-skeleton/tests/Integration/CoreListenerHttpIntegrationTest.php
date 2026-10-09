<?php

namespace App\Tests\Integration;

use App\Common\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;

final class CoreListenerHttpIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private static bool $seeded = false;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();

        if (!self::$seeded) {
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $content = new Content('seed-title', 'seed-body');
            $em->persist($content);
            $em->flush();
            self::$seeded = true;
        }

        self::ensureKernelShutdown();
    }

    public function testExceptionInterceptorReturnsJsonOnApiErrorInTestEnv(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/v1/manage/contents', server: ['CONTENT_TYPE' => 'application/json'], content: '{bad json');

        $response = $client->getResponse();
        self::assertTrue(in_array($response->getStatusCode(), [200, 400], true));
        self::assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }

    public function testCoreListenersDoNotBreakNormalApiFlow(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/contents?limit=5');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }
}
