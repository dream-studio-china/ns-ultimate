<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Repository\UserRepository;
use App\Identity\Security\TokenManager;
use App\Identity\Service\OtpServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/auth/otp', name: 'sys-auth-otp-')]
class OtpController
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly UserRepository $userRepository,
        private readonly OtpServiceInterface $otpService,
        private readonly EntityManagerInterface $em,
        private readonly string $otpLoginTemplate,
        private readonly string $otpVerifyPhoneTemplate,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/request', methods: ['POST'])]
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

    #[Route('/verify', methods: ['POST'])]
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

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse([
            'code' => $status,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
