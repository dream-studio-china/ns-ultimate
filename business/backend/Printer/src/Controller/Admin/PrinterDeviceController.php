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
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Service\PrinterDeviceService;
use NsUltimate\Business\Printer\Service\PrinterCredentialCipher;
use NsUltimate\Business\Printer\Service\XpyunApiException;
use NsUltimate\Business\Printer\Service\XpyunClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/devices', name: 'manage-printer-devices-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterDeviceController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['account', 'name', 'serialNumber', 'kind'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['account', 'name', 'serialNumber', 'kind', 'storeUuid', 'model', 'widthMm', 'heightMm'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'model', 'storeUuid', 'widthMm', 'heightMm', 'enabled'];

    public function __construct(
        protected readonly PrinterDeviceService $service,
        private readonly EntityManagerInterface $entityManager,
        private readonly PrinterCredentialCipher $cipher,
        private readonly XpyunClient $xpyun,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!is_int($content['account'] ?? null) || $content['account'] < 1) {
            throw new \InvalidArgumentException('A printer account is required.');
        }
        foreach (['name', 'serialNumber', 'kind'] as $field) {
            if (!is_string($content[$field] ?? null) || trim($content[$field]) === '') {
                throw new \InvalidArgumentException(sprintf('%s is required.', $field));
            }
            $content[$field] = trim($content[$field]);
        }
        if (!in_array($content['kind'], [PrinterDevice::KIND_RECEIPT, PrinterDevice::KIND_LABEL], true)) {
            throw new \InvalidArgumentException('Printer kind must be receipt or label.');
        }
        foreach (['widthMm', 'heightMm'] as $dimension) {
            if (isset($content[$dimension]) && !is_int($content[$dimension])) {
                throw new \InvalidArgumentException(sprintf('%s must be an integer.', $dimension));
            }
        }
        return $content;
    }

    /** @param array<string, mixed> $content */
    protected function processEntity(array $content, object $entity): object
    {
        $account = $this->entityManager->getRepository(PrinterAccount::class)->find($content['account']);
        if (!$account instanceof PrinterAccount || !$account->isEnabled()) {
            throw new \InvalidArgumentException('Select an enabled printer account.');
        }
        $device = new PrinterDevice(
            $account,
            $content['name'],
            $content['serialNumber'],
            $content['kind'],
            $content['storeUuid'] ?? null,
            $content['model'] ?? null,
            $content['widthMm'] ?? null,
            $content['heightMm'] ?? null,
        );
        $credentials = $this->cipher->decrypt($account->getCredentialsCipher());
        try {
            $response = $this->xpyun->register($account->getDeveloperId(), $credentials['userKey'], $device->getSerialNumber(), $device->getName());
        } catch (XpyunApiException $exception) {
            throw new \InvalidArgumentException('Xpyun rejected printer registration ('.$exception->providerCode.').');
        }
        $success = $response['data']['success'] ?? [];
        if (!is_array($success) || !in_array($device->getSerialNumber(), $success, true)) {
            throw new \InvalidArgumentException('Xpyun did not confirm device registration.');
        }
        return $device;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if (!$entity instanceof PrinterDevice) {
            return $this->warning('Printer device not found.', 404, '', 404);
        }
        if ($this->entityManager->getRepository(PrinterJob::class)->count(['device' => $entity]) > 0) {
            return $this->warning('A printer device with print jobs cannot be deleted.', 409, '', 409);
        }
        return null;
    }
}
