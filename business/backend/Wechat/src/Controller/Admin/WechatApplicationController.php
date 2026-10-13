<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Service\WechatApplicationService;
use NsUltimate\Business\Wechat\Service\WechatSecretCipher;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-applications', name: 'manage-wechat-applications-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatApplicationController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'type', 'appId', 'secret'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'type', 'appId', 'secret', 'token', 'aesKey'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'type', 'appId', 'secret', 'token', 'aesKey'];

    public function __construct(
        protected readonly WechatApplicationService $service,
        private readonly WechatSecretCipher $cipher,
    ) {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof WechatApplication) {
            throw new \LogicException('Unexpected application entity.');
        }
        $credentials = ['secret' => $this->required($content, 'secret')];
        foreach (['token' => 'token', 'aesKey' => 'aes_key'] as $field => $key) {
            if (isset($content[$field]) && is_string($content[$field]) && trim($content[$field]) !== '') {
                $credentials[$key] = trim($content[$field]);
            }
            unset($content[$field]);
        }
        $entity->setCredentialsCipher($this->cipher->encrypt($credentials));
        unset($content['secret']);

        return $content;
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof WechatApplication) {
            throw new \LogicException('Unexpected application entity.');
        }
        if ((isset($content['appId']) && $content['appId'] !== $entity->getAppId())
            || (isset($content['type']) && $content['type'] !== $entity->getType())) {
            throw new \InvalidArgumentException('AppID and application type cannot be changed after creation.');
        }
        unset($content['appId'], $content['type']);
        $credentials = $entity->decryptCredentials($this->cipher);
        $changed = false;
        foreach (['secret' => 'secret', 'token' => 'token', 'aesKey' => 'aes_key'] as $field => $key) {
            if (isset($content[$field]) && is_string($content[$field]) && trim($content[$field]) !== '') {
                $credentials[$key] = trim($content[$field]);
                $changed = true;
            }
            unset($content[$field]);
        }
        if ($changed) {
            $entity->setCredentialsCipher($this->cipher->encrypt($credentials));
        }

        return $content;
    }

    /** @param array<string, mixed> $content */
    private function required(array $content, string $field): string
    {
        $value = $content[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }
        return trim($value);
    }
}
