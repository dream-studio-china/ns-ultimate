<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthBlackBoxIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Identity\\Entity\\RefreshToken r')->execute();
        $em->createQuery('DELETE FROM App\\Identity\\Entity\\User u')->execute();

        self::ensureKernelShutdown();
    }

    public function testLoginRejectsMissingFieldsAndWrongPassword(): void
    {
        $this->createUser('blackbox@example.com', 'blackbox', 'CorrectPass123!', '+8613800001111', true);

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'blackbox@example.com',
            'password' => '',
        ]);
        self::assertResponseStatusCodeSame(400);

        $payload = $this->decodeJsonResponse($client);
        self::assertStringContainsString('required', (string) ($payload['message'] ?? ''));

        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'blackbox@example.com',
            'password' => 'WrongPass',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRevokesAccessTokenFromAuthorizationHeader(): void
    {
        $this->createUser('revoker@example.com', 'revoker', 'RevokerPass123!', '+8613800002222', true);

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'revoker@example.com',
            'password' => 'RevokerPass123!',
        ]);
        self::assertResponseStatusCodeSame(200);
        $auth = $this->extractAuthPayload($client);
        $accessToken = (string) $auth['access_token'];

        self::ensureKernelShutdown();
        $apiClient = static::createClient();
        $apiClient->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $accessToken);
        $apiClient->request('GET', '/api/v1/manage/contents?limit=5');
        self::assertSame(200, $apiClient->getResponse()->getStatusCode());

        $apiClient->request(
            'POST',
            '/api/auth/logout',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: ''
        );
        self::assertSame(204, $apiClient->getResponse()->getStatusCode());

        self::ensureKernelShutdown();
        $revokedClient = static::createClient();
        $revokedClient->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $accessToken);
        $revokedClient->request('GET', '/api/v1/manage/contents?limit=5');
        self::assertSame(401, $revokedClient->getResponse()->getStatusCode());

        $response = $this->decodeJsonResponse($revokedClient);
        self::assertStringContainsString('Invalid or expired JWT token', (string) ($response['message'] ?? ''));
    }

    public function testRefreshTokenReuseDetectionRevokesAllActiveTokens(): void
    {
        $this->createUser('reuse@example.com', 'reuse', 'ReusePass123!', '+8613800003333', true);

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'reuse@example.com',
            'password' => 'ReusePass123!',
        ]);
        self::assertResponseStatusCodeSame(200);
        $login = $this->extractAuthPayload($client);
        $refresh1 = (string) $login['refresh_token'];

        $client->jsonRequest('POST', '/api/auth/token/refresh', ['refresh_token' => $refresh1]);
        self::assertResponseStatusCodeSame(200);
        $rotated = $this->extractAuthPayload($client);
        $refresh2 = (string) $rotated['refresh_token'];
        self::assertNotSame($refresh1, $refresh2, 'Refresh token must rotate on refresh');

        $client->jsonRequest('POST', '/api/auth/token/refresh', ['refresh_token' => $refresh1]);
        self::assertResponseStatusCodeSame(401);

        $client->jsonRequest('POST', '/api/auth/token/refresh', ['refresh_token' => $refresh2]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRevokesRefreshToken(): void
    {
        $this->createUser('logoutrevoke@example.com', 'logoutrevoke', 'LogoutPass123!', '+8613800004444', true);

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'logoutrevoke@example.com',
            'password' => 'LogoutPass123!',
        ]);
        self::assertResponseStatusCodeSame(200);
        $login = $this->extractAuthPayload($client);
        $refreshToken = (string) $login['refresh_token'];

        $client->jsonRequest('POST', '/api/auth/logout', ['refresh_token' => $refreshToken]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('POST', '/api/auth/token/refresh', ['refresh_token' => $refreshToken]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOtpRequestIsRateLimitedAndWrongOtpIsRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->jsonRequest('POST', '/api/auth/otp/request', [
            'phone' => '+8613900001234',
            'purpose' => 'login',
        ]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('POST', '/api/auth/otp/request', [
            'phone' => '+8613900001234',
            'purpose' => 'login',
        ]);
        self::assertResponseStatusCodeSame(429);

        $client->jsonRequest('POST', '/api/auth/otp/verify', [
            'phone' => '+8613900001234',
            'purpose' => 'login',
            'otp' => '000000',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    private function createUser(string $email, string $username, string $plainPassword, ?string $phone, bool $phoneVerified): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPhone($phone);
        $user->setPhoneVerified($phoneVerified);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $em->persist($user);
        $em->flush();

        self::ensureKernelShutdown();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse($client): array
    {
        $content = (string) $client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAuthPayload($client): array
    {
        $json = $this->decodeJsonResponse($client);

        if (isset($json['data']) && \is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }
}
