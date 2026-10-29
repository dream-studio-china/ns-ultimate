<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Service\OrderPrintRuleValidator;
use NsUltimate\Business\Printer\Service\PrinterOrderRuleService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/order-rules', name: 'manage-printer-order-rules-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterOrderRuleController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    protected array $requiredCreateProperties = ['storeUuid', 'name', 'enabled', 'events', 'match', 'conditions', 'scene', 'deviceUuid', 'templateUuid', 'copies'];
    protected array $acceptedCreateProperties = ['storeUuid', 'name', 'enabled', 'events', 'match', 'conditions', 'scene', 'deviceUuid', 'templateUuid', 'copies'];
    protected array $acceptedUpdateProperties = ['name', 'enabled', 'events', 'match', 'conditions', 'scene', 'deviceUuid', 'templateUuid', 'copies'];

    public function __construct(
        protected readonly PrinterOrderRuleService $service,
        private readonly OrderPrintRuleValidator $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof PrinterOrderRule) { throw new \LogicException('Unexpected printer rule entity.'); }
        $this->validateContent($content, $entity);
        return $content;
    }

    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof PrinterOrderRule) { throw new \LogicException('Unexpected printer rule entity.'); }
        $this->validateContent($content, $entity);
        return $content;
    }

    private function validateContent(array $content, PrinterOrderRule $entity): void
    {
        $data = array_replace($entity->toRule(), $content);
        $storeUuid = $content['storeUuid'] ?? $entity->getStoreUuid();
        if (!is_string($storeUuid)) { throw new \InvalidArgumentException('A valid store is required.'); }
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        if (!$store instanceof Store) { throw new \InvalidArgumentException('A valid store is required.'); }
        $this->validator->validateForStore([$data], $store, $this->entityManager);
    }
}
