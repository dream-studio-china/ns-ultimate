<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Service\WechatMerchantService;
use NsUltimate\Business\Wechat\Service\WechatSecretCipher;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-merchants', name: 'manage-wechat-merchants-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatMerchantController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    protected array $requiredCreateProperties = ['name', 'merchantId', 'apiV3Key', 'privateKey', 'certificate'];
    protected array $acceptedCreateProperties = ['name', 'merchantId', 'apiV3Key', 'privateKey', 'certificate', 'platformCertificate'];
    protected array $acceptedUpdateProperties = ['name', 'merchantId', 'apiV3Key', 'privateKey', 'certificate', 'platformCertificate'];

    public function __construct(
        protected readonly WechatMerchantService $service,
        private readonly WechatSecretCipher $cipher,
    ) {
    }

    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof WechatMerchant) {
            throw new \LogicException('Unexpected merchant entity.');
        }
        $credentials = [];
        foreach (['apiV3Key' => 'api_v3_key', 'privateKey' => 'private_key', 'certificate' => 'certificate'] as $field => $key) {
            $credentials[$key] = $this->required($content, $field);
            unset($content[$field]);
        }
        $credentials['platform_certificate'] = $this->required($content, 'platformCertificate');
        $this->validatePlatformCertificate($credentials['platform_certificate']);
        unset($content['platformCertificate']);
        $entity->setCredentialsCipher($this->cipher->encrypt($credentials));

        return $content;
    }

    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof WechatMerchant) {
            throw new \LogicException('Unexpected merchant entity.');
        }
        if (isset($content['merchantId']) && $content['merchantId'] !== $entity->getMerchantId()) {
            throw new \InvalidArgumentException('Merchant ID cannot be changed after creation.');
        }
        unset($content['merchantId']);
        $credentials = $entity->decryptCredentials($this->cipher);
        $changed = false;
        foreach (['apiV3Key' => 'api_v3_key', 'privateKey' => 'private_key', 'certificate' => 'certificate', 'platformCertificate' => 'platform_certificate'] as $field => $key) {
            if (isset($content[$field]) && is_string($content[$field]) && trim($content[$field]) !== '') {
                $value = trim($content[$field]);
                if ($field === 'platformCertificate') {
                    $this->validatePlatformCertificate($value);
                }
                $credentials[$key] = $value;
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

    private function validatePlatformCertificate(string $certificate): void
    {
        if (openssl_x509_read($certificate) === false) {
            throw new \InvalidArgumentException('platformCertificate must contain a valid PEM-encoded X.509 certificate.');
        }
    }
}
