<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Common\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TokenRevocationIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Common\\Entity\\Content c')->execute();
        self::ensureKernelShutdown();
    }

    public function testAccessTokenIsRevokedAfterLogout(): void
    {
        // Create an authenticated client and extract the access token
        $client = static::createAuthenticatedClient();

        // Read from server parameters, not BrowserKit request object.
        $authHeader = (string) $client->getServerParameter('HTTP_AUTHORIZATION');

        // The Authorization header should be "Bearer <token>"
        self::assertStringStartsWith('Bearer ', $authHeader, 'Authorization header should have Bearer token');
        $accessToken = substr($authHeader, 7);

        // First request with the token should succeed
        $client->request('GET', '/api/v1/manage/contents?limit=10');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // Now logout with the access token
        $client->jsonRequest('POST', '/api/auth/logout', ['access_token' => $accessToken]);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // Now try to use the revoked access token for an API call
        // Create a fresh client with the same revoked token
        self::ensureKernelShutdown();
        $revokedClient = static::createClient();
        $revokedClient->setServerParameter('HTTP_Authorization', 'Bearer ' . $accessToken);
        
        $revokedClient->request('GET', '/api/v1/manage/contents?limit=10');
        
        // The request should fail with 401 Unauthorized
        self::assertSame(401, $revokedClient->getResponse()->getStatusCode());
        
        $response = json_decode((string) $revokedClient->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('message', $response);
        self::assertStringContainsString('Invalid or expired JWT', $response['message']);
    }

    public function testAccessTokenStillWorksBeforeLogout(): void
    {
        // Create an authenticated client and extract the access token
        $client = static::createAuthenticatedClient();

        // Read from server parameters, not BrowserKit request object.
        $authHeader = (string) $client->getServerParameter('HTTP_AUTHORIZATION');

        self::assertStringStartsWith('Bearer ', $authHeader);
        $accessToken = substr($authHeader, 7);

        // Create a fresh client with the same token
        self::ensureKernelShutdown();
        $testClient = static::createClient();
        $testClient->setServerParameter('HTTP_Authorization', 'Bearer ' . $accessToken);
        
        // Should work before logout
        $testClient->request('GET', '/api/v1/manage/contents?limit=10');
        self::assertSame(200, $testClient->getResponse()->getStatusCode());
    }

    #[Group('low-value')]
    public function testRefreshTokenIsRevokedAfterLogout(): void
    {
        $client = static::createAuthenticatedClient();
        
        // For this test, we need to use the login endpoint to get both tokens
        // Since createAuthenticatedClient only provides access token, let's create new test
        // that logs in first to get both tokens
        
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['identifier' => 'testauth@example.com', 'password' => 'TestPass123!'], JSON_THROW_ON_ERROR)
        );
        
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $loginResponse = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $loginData = isset($loginResponse['data']) && \is_array($loginResponse['data'])
            ? $loginResponse['data']
            : $loginResponse;
        self::assertArrayHasKey('access_token', $loginData);
        self::assertArrayHasKey('refresh_token', $loginData);

        $accessToken = $loginData['access_token'];
        $refreshToken = $loginData['refresh_token'];

        // Logout with both tokens
        $client->request(
            'POST',
            '/api/auth/logout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
            ], JSON_THROW_ON_ERROR)
        );
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // Try to use refresh token should fail
        $client->request(
            'POST',
            '/api/auth/token/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $refreshToken], JSON_THROW_ON_ERROR)
        );
        
        // Should fail because refresh token is revoked
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
