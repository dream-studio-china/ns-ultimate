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
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/stores/{storeUuid}/wechat', name: 'app-store-wechat-', requirements: ['storeUuid' => '[0-9a-fA-F-]{36}'])]
final class WechatLoginController extends RestController
{
    public function __construct(
        private readonly StoreWechatLoginService $loginService,
        private readonly TokenManager $tokenManager,
    ) {
    }

    #[Route('/miniapp/login', name: 'miniapp-login', methods: ['POST'])]
    public function miniappLogin(Request $request, string $storeUuid): JsonResponse
    {
        $jsCode = $this->stringField($request, 'js_code');
        if ($jsCode === null) {
            return $this->error('js_code is required.');
        }

        try {
            $user = $this->loginService->loginWithMiniApp($storeUuid, $jsCode);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (HttpExceptionInterface $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }

        return $this->tokenResponse($user);
    }

    #[Route('/miniapp/phone', name: 'miniapp-phone', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function miniappPhone(Request $request, string $storeUuid, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if ($user === null) {
            return $this->error('Authentication required.', 401);
        }

        $code = $this->stringField($request, 'code');
        if ($code === null) {
            return $this->error('code is required.');
        }

        try {
            $this->loginService->bindPhone($user, $storeUuid, $code);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (HttpExceptionInterface $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/miniapp/link', name: 'miniapp-link', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function miniappLink(Request $request, string $storeUuid, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if ($user === null) {
            return $this->error('Authentication required.', 401);
        }

        $jsCode = $this->stringField($request, 'js_code');
        if ($jsCode === null) {
            return $this->error('js_code is required.');
        }

        try {
            $identity = $this->loginService->linkIdentity($user, $storeUuid, $jsCode);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (HttpExceptionInterface $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }

        return new JsonResponse([
            'uuid' => $identity->getUuid(),
            'appId' => $identity->getAppId(),
            'openid' => $identity->getOpenid(),
        ]);
    }

    private function tokenResponse(User $user): JsonResponse
    {
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
