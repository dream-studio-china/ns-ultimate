<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Business\HuifuFin\Service\HuifuProviderProfileService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-provider-profiles', name: 'manage-huifu-provider-profiles-')]
#[IsGranted('ROLE_ADMIN')]
final class HuifuProviderProfileController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = [
        'name', 'environment', 'sysId', 'productId', 'merchantPrivateKey', 'huifuPublicKey',
    ];
    /** @var list<string> */
    protected array $acceptedCreateProperties = [
        'name', 'environment', 'sysId', 'productId', 'merchantPrivateKey', 'huifuPublicKey',
        'asyncPublicKey', 'enabled',
    ];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = [
        'name', 'environment', 'sysId', 'productId', 'merchantPrivateKey', 'huifuPublicKey',
        'asyncPublicKey', 'enabled',
    ];

    public function __construct(
        protected readonly HuifuProviderProfileService $service,
        private readonly HuifuSecretCipher $cipher,
    ) {
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof HuifuProviderProfile) {
            throw new \LogicException('Unexpected Huifu provider profile entity.');
        }
        $credentials = [
            'merchant_private_key' => $this->required($content, 'merchantPrivateKey'),
            'huifu_public_key' => $this->required($content, 'huifuPublicKey'),
        ];
        if (isset($content['asyncPublicKey']) && is_string($content['asyncPublicKey']) && trim($content['asyncPublicKey']) !== '') {
            $credentials['async_public_key'] = trim($content['asyncPublicKey']);
        }
        $entity->setCredentials($credentials, $this->cipher);
        unset($content['merchantPrivateKey'], $content['huifuPublicKey'], $content['asyncPublicKey']);

        return $content;
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof HuifuProviderProfile) {
            throw new \LogicException('Unexpected Huifu provider profile entity.');
        }
        $credentials = null;
        foreach (['merchantPrivateKey' => 'merchant_private_key', 'huifuPublicKey' => 'huifu_public_key', 'asyncPublicKey' => 'async_public_key'] as $field => $key) {
            if (isset($content[$field]) && is_string($content[$field]) && trim($content[$field]) !== '') {
                $credentials ??= $entity->decryptCredentials($this->cipher);
                $credentials[$key] = trim($content[$field]);
            }
            unset($content[$field]);
        }
        if ($credentials !== null) {
            $entity->setCredentials($credentials, $this->cipher);
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
