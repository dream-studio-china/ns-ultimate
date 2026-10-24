<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\Store;

use App\Core\Controller\RestController;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use NsUltimate\Business\Counter\Service\CounterService;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Service\CounterSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/counters', name: 'store-counters-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class CounterController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['code', 'name'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['code', 'name', 'capacity', 'sort', 'metadata', 'enabled'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['code', 'name', 'capacity', 'sort', 'metadata', 'enabled'];

    public function __construct(
        protected readonly CounterService $service,
        private readonly StoreServiceInterface $storeService,
        private readonly EntityManagerInterface $entityManager,
        private readonly CounterSessionService $sessions,
    ) {
    }

    #[Route('/{counterUuid}/sessions/end', name: 'session-end', methods: ['POST'], requirements: ['counterUuid' => '[0-9a-fA-F-]{36}'])]
    public function endSessionAction(string $counterUuid): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $this->authorizeStoreAction('update');
            $store = $this->storeForAuthorization();
            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();
            $counter = $this->entityManager->getRepository(Counter::class)->findOneBy(['uuid' => $counterUuid, 'storeUuid' => $store->getUuid()]);
            if (!$counter instanceof Counter) {
                throw new \DomainException('Counter not found.');
            }
            $session = $this->sessions->end($counter);
            $this->entityManager->flush();
            $connection->commit();
            return $this->success(['counterSessionUuid' => $session->getUuid()], 'Counter session ended');
        } catch (\Throwable $error) {
            if (isset($connection) && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            return $this->warning($error->getMessage(), 400, '', 400);
        }
    }

    /** @return array<string, mixed> */
    protected function storeScopedFilter(Store $store): array
    {
        return ['storeUuid' => $store->getUuid()];
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->storeService;
    }

    protected function storeAuthorizationResource(): string
    {
        return 'counter';
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        $store = $this->storeForAuthorization();
        if ($entity instanceof \NsUltimate\Business\Counter\Entity\Counter) {
            $entity->setStoreUuid($store->getUuid());
        }
        return $this->validateFields($content);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        return $this->validateFields($content);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    private function validateFields(array $content): array
    {
        foreach (['code', 'name'] as $property) {
            if (array_key_exists($property, $content) && (!is_string($content[$property]) || trim($content[$property]) === '')) {
                throw new \InvalidArgumentException(sprintf('%s cannot be empty.', $property));
            }
            if (isset($content[$property])) {
                $content[$property] = trim($content[$property]);
            }
        }
        if (isset($content['capacity']) && (!is_int($content['capacity']) || $content['capacity'] < 1)) {
            throw new \InvalidArgumentException('capacity must be a positive integer.');
        }
        if (isset($content['sort']) && (!is_int($content['sort']) || $content['sort'] < 0)) {
            throw new \InvalidArgumentException('sort must be a non-negative integer.');
        }
        if (array_key_exists('metadata', $content) && $content['metadata'] !== null && !is_array($content['metadata'])) {
            throw new \InvalidArgumentException('metadata must be an object or null.');
        }
        return $content;
    }
}
