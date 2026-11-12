<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Service\PrinterAccountService;
use NsUltimate\Business\Printer\Service\PrinterCredentialCipher;
use NsUltimate\Business\Printer\Service\PrinterProviderRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/accounts', name: 'manage-printer-accounts-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterAccountController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'developerId', 'userKey'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'developerId', 'userKey', 'provider'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'enabled'];

    public function __construct(
        protected readonly PrinterAccountService $service,
        private readonly PrinterCredentialCipher $cipher,
        private readonly PrinterProviderRegistry $providers,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof PrinterAccount) { throw new \LogicException('Unexpected printer account entity.'); }
        $provider = $content['provider'] ?? PrinterAccount::PROVIDER_XPYUN;
        if (!is_string($provider) || !$this->providers->has($provider)) {
            throw new \InvalidArgumentException('This printer provider is not configured.');
        }
        $entity->setProvider($provider);
        $userKey = $content['userKey'] ?? null;
        if (!is_string($userKey) || trim($userKey) === '') { throw new \InvalidArgumentException('userKey is required.'); }
        if (!is_string($content['name'] ?? null) || trim($content['name']) === '' || !is_string($content['developerId'] ?? null) || trim($content['developerId']) === '') {
            throw new \InvalidArgumentException('Account name and developerId are required.');
        }
        $entity->setCredentialsCipher($this->cipher->encrypt($userKey));
        unset($content['userKey']);
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (isset($content['name']) && (!is_string($content['name']) || trim($content['name']) === '')) {
            throw new \InvalidArgumentException('Account name cannot be empty.');
        }
        if (isset($content['name'])) { $content['name'] = trim($content['name']); }
        return $content;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if (!$entity instanceof PrinterAccount) {
            return $this->warning('Printer account not found.', 404, '', 404);
        }
        if ($this->entityManager->getRepository(PrinterDevice::class)->count(['account' => $entity]) > 0) {
            return $this->warning('A printer account with devices cannot be deleted.', 409, '', 409);
        }
        return null;
    }
}
