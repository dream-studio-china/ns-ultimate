<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\App;

use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use NsUltimate\Business\Wechat\Service\StoreWechatLoginService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/stores/{storeUuid}/wechat/oauth', name: 'app-store-wechat-oauth-', requirements: ['storeUuid' => '[0-9a-fA-F-]{36}'])]
final class WechatOauthController extends RestController
{
    public function __construct(
        private readonly StoreWechatLoginService $loginService,
        private readonly TokenManager $tokenManager,
    ) {
    }

    #[Route('/url', name: 'url', methods: ['GET'])]
    public function url(Request $request, string $storeUuid): JsonResponse
    {
        $redirectUri = trim((string) $request->query->get('redirect_uri', ''));
        if ($redirectUri === '') {
            return $this->error('redirect_uri is required.');
        }

        try {
            $url = $this->loginService->oauthUrl($storeUuid, $redirectUri);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (HttpExceptionInterface $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return new JsonResponse(['url' => $url]);
    }

    #[Route('/callback', name: 'callback', methods: ['POST'])]
    public function callback(Request $request, string $storeUuid): JsonResponse
    {
        $code = $this->stringField($request, 'code');
        $state = $this->stringField($request, 'state');
        if ($code === null || $state === null) {
            return $this->error('code and state are required.');
        }

        try {
            $user = $this->loginService->oauthCallback($storeUuid, $code, $state);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (HttpExceptionInterface $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }

        return new JsonResponse([
            'access_token' => $this->tokenManager->createAccessToken($user),
            'expires_in' => $this->tokenManager->getAccessTtl(),
            'refresh_token' => $this->tokenManager->createRefreshToken($user),
        ]);
    }

    private function stringField(Request $request, string $key): ?string
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['code' => $status, 'message' => $message], $status);
    }
}
