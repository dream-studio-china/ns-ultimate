<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\Utils\UUID;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Accounting\Controller\LedgerControllerSupport;
use NsUltimate\Business\Accounting\Entity\JournalEntry;
use NsUltimate\Business\Accounting\Repository\LedgerReadRepository;
use NsUltimate\Business\Accounting\Service\JournalEntryService;
use NsUltimate\Business\Accounting\Service\LedgerAccess;
use NsUltimate\Business\Accounting\Service\LedgerCommands;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\LedgerPage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Journal register as a standard EasyAdmin resource.
 *
 * Reads use the Core list/detail ViewMixins with fixed Finance projections.
 * `POST /journals` and `PUT /journals/{id}` only create or revise drafts and
 * delegate to the ledger command service, which keeps the book lock, revision
 * check, and audit. Posting, voiding, and reversal remain explicit commands;
 * no generic delete or batch write is exposed.
 */
#[Route('/journals', name: 'journals_')]
final class JournalController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;
    use LedgerControllerSupport;

    public function __construct(
        protected readonly JournalEntryService $service,
        private readonly LedgerAccess $access,
        private readonly LedgerReadRepository $readModel,
        private readonly LedgerCommands $commands,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $user = $this->financeUser('draft');
        // A client-supplied command_id makes an exact retry return the same draft.
        // Without one the request is an ordinary, non-replayable create.
        $data = $this->ledgerPayload($request) + ['command_id' => UUID::v4()];
        $result = $this->commands->execute($user->getUuid(), 'create-draft', $data, $this->headquartersBookId());

        return $this->success($this->projection($user, $result['id']), status: 201);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    public function updateAction(Request $request, string $id): Response
    {
        $user = $this->financeUser('draft');
        $id = LedgerInput::uuid($id);
        $data = $this->ledgerPayload($request);
        if (array_key_exists('id', $data) && $data['id'] !== $id) {
            throw new LedgerError('invalid_identifier');
        }
        $result = $this->commands->execute($user->getUuid(), 'update-draft', ['id' => $id] + $data, $this->headquartersBookId());

        return $this->success($this->projection($user, $result['id']));
    }

    protected function authorizeApiAction(string $action, ?object $entity = null): void
    {
        $user = $this->getUser();
        if (!$user instanceof User || !in_array('read', $this->access->capabilities($user), true)) {
            throw new AccessDeniedException('finance_access_required');
        }
        if ($action !== 'list') {
            LedgerInput::fields($this->parameters());
        }
    }

    /** @return array{book: int} */
    protected function commonFilter(): array
    {
        return ['book' => $this->headquartersBookId()];
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
        return $this->service->registerQuery($this->parameters(), $this->headquartersBookId());
    }

    protected function listResponses(mixed $entities): LedgerPage
    {
        if (!$entities instanceof QueryBuilder) {
            throw new \LogicException('Expected a journal register query.');
        }
        [$page, $limit] = LedgerPage::window($this->parameters());

        return $this->service->registerPage($entities, $page, $limit);
    }

    protected function detailResponse(?object $entity): ?object
    {
        if (!$entity instanceof JournalEntry) {
            return null;
        }
        $user = $this->getUser();

        return $user instanceof User ? $this->projection($user, $entity->getUuid()) : null;
    }

    private function projection(User $user, string $id): object
    {
        return (object) $this->readModel->journal($id, in_array('audit', $this->access->capabilities($user), true), $this->headquartersBookId());
    }

    private function financeUser(string $capability): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new LedgerError('authentication_required', 401);
        }
        $capabilities = $this->access->capabilities($user);
        if (!in_array('read', $capabilities, true) || !in_array($capability, $capabilities, true)) {
            throw new LedgerError('finance_access_required', 403);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function parameters(): array
    {
        $request = $this->getRequestStack()->getCurrentRequest();

        return $request === null ? [] : $this->ledgerParameters($request);
    }
}
