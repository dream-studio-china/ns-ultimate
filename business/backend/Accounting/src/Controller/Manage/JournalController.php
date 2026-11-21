<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\JournalEntry;
use NsUltimate\Business\Accounting\Repository\LedgerReadRepository;
use NsUltimate\Business\Accounting\Service\JournalEntryService;
use NsUltimate\Business\Accounting\Service\LedgerAccess;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/journals', name: 'journals_')]
final class JournalController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly JournalEntryService $service,
        private readonly LedgerAccess $access,
        private readonly LedgerReadRepository $readModel,
    ) {}

    protected function authorizeApiAction(string $action, ?object $entity = null): void
    {
        $user = $this->getUser();
        if (!$user instanceof User || !in_array('read', $this->access->capabilities($user), true)) {
            throw new AccessDeniedException('finance_access_required');
        }
        if ($action === 'list') {
            LedgerInput::page($this->parameters()['page'] ?? '1');
        } else {
            LedgerInput::fields($this->parameters());
        }
    }

    /** @return array{book: int} */
    protected function commonFilter(): array
    {
        return ['book' => AccountingBook::ID];
    }

    /** @return array{uuid: string} */
    protected function identifierCriteria(int|string $value): array
    {
        return ['uuid' => LedgerInput::uuid($value)];
    }

    /** @param array<string, mixed>|QueryBuilder|DqlExpression|null $filter */
    protected function listFilter(array|QueryBuilder|DqlExpression|null $filter = null): QueryBuilder
    {
        // A fixed query whitelist keeps all of Core's dynamic query inputs off this view.
        return $this->service->registerQuery($this->parameters());
    }

    protected function listResponses(mixed $entities): object
    {
        if (!$entities instanceof QueryBuilder) {
            throw new \LogicException('Expected a journal register query.');
        }

        return $this->service->registerPage($entities, LedgerInput::page($this->parameters()['page'] ?? '1'));
    }

    protected function detailResponse(?object $entity): ?object
    {
        if (!$entity instanceof JournalEntry) {
            return null;
        }
        $user = $this->getUser();
        $evidence = $user instanceof User && in_array('audit', $this->access->capabilities($user), true);

        return (object) $this->readModel->journal($entity->getUuid(), $evidence);
    }

    /** @return array<string, mixed> */
    private function parameters(): array
    {
        $all = $this->getRequestStack()->getCurrentRequest()?->query->all() ?? [];
        unset($all['_locale']);

        return $all;
    }
}
