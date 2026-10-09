<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Security\TokenManager;
use App\Identity\Service\OtpServiceInterface;
use App\Identity\Service\UserServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/auth', name: 'sys-auth-')]
class AuthController
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly OtpServiceInterface $otpService,
        private readonly UserServiceInterface $userService,
        private readonly EntityManagerInterface $em,
        private readonly string $otpLoginTemplate,
        private readonly string $otpVerifyPhoneTemplate,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Login with identifier and password',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['identifier', 'password'],
                properties: [
                    new OA\Property(
                        property: 'identifier',
                        type: 'string',
                        description: 'Email, username, or phone number',
                        example: 'admin@example.com'
                    ),
                    new OA\Property(
                        property: 'password',
                        type: 'string',
                        format: 'password',
                        minLength: 1,
                        description: 'Plain password. Must not be empty.',
                        example: 'P@ssw0rd'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login success, tokens returned'),
            new OA\Response(response: 400, description: 'Identifier or password missing'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 403, description: 'Phone not verified'),
        ],
        tags: ['Auth']
    )]
    #[Route('/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $identifier = trim((string) ($data['identifier'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($identifier === '' || $password === '') {
            return $this->error('Identifier and password are required.', Response::HTTP_BAD_REQUEST);
        }

        // Phone-based login: check verification status separately
        if ($this->looksLikePhone($identifier)) {
            $user = $this->userRepository->findByPhone($identifier);
            if ($user !== null && !$user->isPhoneVerified()) {
                return $this->error('Phone not verified.', Response::HTTP_FORBIDDEN);
            }
        } else {
            $user = $this->userRepository->findByIdentifier($identifier);
        }

        if ($user === null) {
            return $this->error('Invalid credentials.', Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->error('Invalid credentials.', Response::HTTP_UNAUTHORIZED);
        }

        $accessToken = $this->tokenManager->createAccessToken($user);
        $refreshToken = $this->tokenManager->createRefreshToken($user);

        return new JsonResponse([
            'access_token' => $accessToken,
            'expires_in' => $this->tokenManager->getAccessTtl(),
            'refresh_token' => $refreshToken,
        ]);
    }

    #[OA\Post(
        path: '/api/auth/register',
        summary: 'Register a new user account',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'username', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                    new OA\Property(property: 'username', type: 'string', example: 'newuser'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'P@ssw0rd'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, description: 'Optional phone number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Registration success, tokens returned'),
            new OA\Response(response: 400, description: 'Missing fields or weak password'),
            new OA\Response(response: 409, description: 'Email, username, or phone already exists'),
        ],
        tags: ['Auth']
    )]
    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $email = trim((string) ($data['email'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;

        try {
            $user = $this->userService->register($email, $username, $password, $phone);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $accessToken = $this->tokenManager->createAccessToken($user);
        $refreshToken = $this->tokenManager->createRefreshToken($user);

        return new JsonResponse([
            'access_token' => $accessToken,
            'expires_in' => $this->tokenManager->getAccessTtl(),
            'refresh_token' => $refreshToken,
        ], Response::HTTP_CREATED);
    }

    private function looksLikePhone(string $value): bool
    {
        return (bool) preg_match('/^\+?[0-9]{7,20}$/', $value);
    }

    #[OA\Post(
        path: '/api/auth/otp/request',
        summary: 'Request OTP code',
        description: 'Generate and send an OTP code to the specified phone number',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'purpose'],
                properties: [
                    new OA\Property(
                        property: 'phone',
                        type: 'string',
                        description: 'Phone number in E.164 format',
                        example: '+8613912345678'
                    ),
                    new OA\Property(
                        property: 'purpose',
                        type: 'string',
                        description: 'Purpose of OTP: "login" or "verify_phone"',
                        enum: ['login', 'verify_phone'],
                        example: 'login'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'OTP sent successfully'),
            new OA\Response(response: 400, description: 'Invalid request (missing phone or invalid purpose)'),
            new OA\Response(response: 429, description: 'Too many requests or rate limit exceeded'),
        ],
        tags: ['Auth']
    )]
    #[Route('/otp/request', methods: ['POST'])]
    public function requestOtp(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $phone = trim((string) ($data['phone'] ?? ''));
        $purpose = trim((string) ($data['purpose'] ?? 'login'));

        if ($phone === '') {
            return $this->error('Phone number is required.', Response::HTTP_BAD_REQUEST);
        }

        if (!\in_array($purpose, ['login', 'verify_phone'], true)) {
            return $this->error('Invalid purpose. Must be "login" or "verify_phone".', Response::HTTP_BAD_REQUEST);
        }

        $templateCode = $purpose === 'login' ? $this->otpLoginTemplate : $this->otpVerifyPhoneTemplate;

        try {
            $this->otpService->generateAndSend($phone, $purpose, $templateCode);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_TOO_MANY_REQUESTS);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[OA\Post(
        path: '/api/auth/otp/verify',
        summary: 'Verify OTP code',
        description: 'Verify the OTP code for login or phone verification. Returns tokens if purpose is "login".',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'otp', 'purpose'],
                properties: [
                    new OA\Property(
                        property: 'phone',
                        type: 'string',
                        description: 'Phone number in E.164 format',
                        example: '+8613912345678'
                    ),
                    new OA\Property(
                        property: 'otp',
                        type: 'string',
                        description: 'The 6-digit OTP code received via SMS',
                        example: '123456'
                    ),
                    new OA\Property(
                        property: 'purpose',
                        type: 'string',
                        description: 'Purpose of OTP verification: "login" or "verify_phone"',
                        enum: ['login', 'verify_phone'],
                        example: 'login'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'OTP verified successfully, tokens returned'),
            new OA\Response(response: 400, description: 'Invalid request (missing fields)'),
            new OA\Response(response: 401, description: 'Invalid or expired OTP, or phone not verified'),
        ],
        tags: ['Auth']
    )]
    #[Route('/otp/verify', methods: ['POST'])]
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $phone = trim((string) ($data['phone'] ?? ''));
        $otp = trim((string) ($data['otp'] ?? ''));
        $purpose = trim((string) ($data['purpose'] ?? 'login'));

        if ($phone === '' || $otp === '') {
            return $this->error('Phone and OTP are required.', Response::HTTP_BAD_REQUEST);
        }

        if (!\in_array($purpose, ['login', 'verify_phone'], true)) {
            return $this->error('Invalid purpose.', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->otpService->verify($phone, $purpose, $otp)) {
            return $this->error('Invalid or expired OTP.', Response::HTTP_UNAUTHORIZED);
        }

        if ($purpose === 'login') {
            $user = $this->userRepository->findByPhone($phone);
            if ($user === null || !$user->isPhoneVerified()) {
                return $this->error('Phone not verified or user not found.', Response::HTTP_UNAUTHORIZED);
            }

            $accessToken = $this->tokenManager->createAccessToken($user);
            $refreshToken = $this->tokenManager->createRefreshToken($user);

            return new JsonResponse([
                'access_token' => $accessToken,
                'expires_in' => $this->tokenManager->getAccessTtl(),
                'refresh_token' => $refreshToken,
            ]);
        }

        // purpose === verify_phone
        $user = $this->userRepository->findByPhone($phone);
        if ($user !== null) {
            $user->setPhoneVerified(true);
            $this->em->flush();
        }

        return new JsonResponse(['phone_verified' => true]);
    }

    #[OA\Post(
        path: '/api/auth/token/refresh',
        summary: 'Refresh access token',
        description: 'Use a refresh token to obtain a new access token and refresh token pair',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [
                    new OA\Property(
                        property: 'refresh_token',
                        type: 'string',
                        description: 'The refresh token obtained from login or previous refresh',
                        example: 'eyJhbGc...'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tokens refreshed successfully'),
            new OA\Response(response: 400, description: 'Refresh token missing'),
            new OA\Response(response: 401, description: 'Invalid, expired, or reused refresh token'),
        ],
        tags: ['Auth']
    )]
    #[Route('/token/refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            return $this->error('Refresh token is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $tokens = $this->tokenManager->rotateRefreshToken($refreshToken);

            return new JsonResponse([
                'access_token' => $tokens['access_token'],
                'expires_in' => $this->tokenManager->getAccessTtl(),
                'refresh_token' => $tokens['refresh_token'],
            ]);
        } catch (\RuntimeException $e) {
            // Token reuse or invalid
            return $this->error($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Logout user',
        description: 'Logout user and revoke provided tokens. Supports refresh token and access token revocation.',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'access_token',
                        type: 'string',
                        description: 'Optional access token to revoke. If omitted, Authorization: Bearer token will be used when present',
                        example: 'eyJhbGc...'
                    ),
                    new OA\Property(
                        property: 'refresh_token',
                        type: 'string',
                        description: 'Optional refresh token to revoke',
                        example: 'eyJhbGc...'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Logout successful'),
            new OA\Response(response: 400, description: 'Invalid request format'),
        ],
        tags: ['Auth']
    )]
    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $content = trim($request->getContent());
        $data = $content === '' ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            $data = [];
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));

        if ($accessToken === '') {
            $authHeader = $request->headers->get('Authorization', '');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $accessToken = trim(substr($authHeader, 7));
            }
        }

        if ($accessToken !== '') {
            $this->tokenManager->revokeAccessToken($accessToken);
        }

        if ($refreshToken !== '') {
            $this->tokenManager->revokeRefreshToken($refreshToken);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse([
            'code' => $status,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
