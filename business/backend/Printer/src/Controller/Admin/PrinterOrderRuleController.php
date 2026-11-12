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
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Service\OrderPrintRuleValidator;
use NsUltimate\Business\Printer\Service\PrinterOrderRuleService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/order-rules', name: 'manage-printer-order-rules-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterOrderRuleController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['storeUuid', 'name', 'enabled', 'events', 'match', 'conditions', 'deviceUuid', 'templateUuid', 'copies'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['storeUuid', 'name', 'enabled', 'events', 'match', 'conditions', 'deviceUuid', 'templateUuid', 'copies'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['storeUuid', 'name', 'enabled', 'events', 'match', 'conditions', 'deviceUuid', 'templateUuid', 'copies'];

    public function __construct(
        protected readonly PrinterOrderRuleService $service,
        private readonly OrderPrintRuleValidator $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'])]
    public function updateAction(Request $request, int|string $id): Response
    {
        try {
            $this->authorizeApiAction('update');
            // Hold the target store lock until a reassigned rule has been saved.
            $entity = $this->service->wrapInTransaction(fn () => $this->updateRecords($request, $id));
            return $entity ? $this->success($entity) : $this->warning();
        } catch (\Symfony\Component\Validator\Exception\ValidatorException|\InvalidArgumentException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 404, '', 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            return $this->warning($exception->getMessage(), 403, '', 403);
        } catch (\Exception $exception) {
            return $this->warning($exception->getMessage() ?: self::UNKNOWN_ERROR, 500, '', 500);
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof PrinterOrderRule) { throw new \LogicException('Unexpected printer rule entity.'); }
        $content = $this->normalizeConditions($content);
        $this->validateContent($content, $entity, true);
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof PrinterOrderRule) { throw new \LogicException('Unexpected printer rule entity.'); }
        $content = $this->normalizeConditions($content);
        $this->validateContent($content, $entity);
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function normalizeConditions(array $content): array
    {
        if (!is_array($content['conditions'] ?? null)) { return $content; }
        foreach ($content['conditions'] as &$condition) {
            if (!is_array($condition) || !array_key_exists('valueKind', $condition)) { continue; }
            if ($condition['valueKind'] !== 'null'
                || !in_array($condition['operator'] ?? null, ['equals', 'not_equals'], true)
                || (array_key_exists('value', $condition) && $condition['value'] !== null)) {
                throw new \InvalidArgumentException('Invalid print condition value type.');
            }
            $condition['value'] = null;
            unset($condition['valueKind']);
        }
        unset($condition);
        return $content;
    }

    /** @param array<string, mixed> $content */
    private function validateContent(array $content, PrinterOrderRule $entity, bool $creating = false): void
    {
        $data = array_replace($entity->toRule(), $content);
        $storeUuid = $content['storeUuid'] ?? $entity->getStoreUuid();
        if (!is_string($storeUuid)) { throw new \InvalidArgumentException('A valid store is required.'); }
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        if (!$store instanceof Store) { throw new \InvalidArgumentException('A valid store is required.'); }
        if ($creating || $storeUuid !== $entity->getStoreUuid()) {
            $connection = $this->entityManager->getConnection();
            if (!$connection->isTransactionActive()) {
                throw new \InvalidArgumentException('Rule creation or store reassignment requires a transaction.');
            }
            if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
                // Serialize concurrent additions and reassignments before counting rules.
                $this->entityManager->lock($store, LockMode::PESSIMISTIC_WRITE);
            }
            if ($this->entityManager->getRepository(PrinterOrderRule::class)->count(['storeUuid' => $storeUuid]) >= 50) {
                throw new \InvalidArgumentException('A store may have at most 50 print rules.');
            }
        }
        $this->validator->validateForStore([$data], $store, $this->entityManager);
    }
}
