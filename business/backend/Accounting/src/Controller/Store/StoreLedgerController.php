<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller\Store;

use App\Core\Controller\RestController;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use NsUltimate\Business\Accounting\Controller\LedgerControllerSupport;
use NsUltimate\Business\Accounting\Controller\LedgerCsv;
use NsUltimate\Business\Accounting\Repository\LedgerReadRepository;
use NsUltimate\Business\Accounting\Service\JournalEntryService;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\LedgerPage;
use NsUltimate\Business\Accounting\Service\LedgerWorkspace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/store/{scopeId}/accounting', name: 'ledger_', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
final class StoreLedgerController extends RestController
{
    use StoreScopedAuthorizationApiMixin, LedgerControllerSupport;

    public function __construct(
        private readonly StoreServiceInterface $stores,
        private readonly LedgerWorkspace $workspace,
        private readonly JournalEntryService $journals,
        private readonly LedgerReadRepository $queries,
    ) {}

    #[Route('/workspace', name: 'workspace', methods: ['GET'])]
    public function workspace(Request $request): Response
    {
        $id = $this->authorizedBook();
        LedgerInput::fields($this->ledgerParameters($request));

        return $this->success((object) $this->workspace->setup($id));
    }

    #[Route('/journals', name: 'journals', methods: ['GET'])]
    public function journals(Request $request): Response
    {
        $id = $this->authorizedBook();
        $params = $this->ledgerParameters($request);
        [$page, $limit] = LedgerPage::window($params);

        return $this->success($this->journals->registerPage($this->journals->registerQuery($params, $id), $page, $limit));
    }

    #[Route('/journals/{id}', name: 'journal', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function journal(string $id, Request $request): Response
    {
        $bookId = $this->authorizedBook();
        LedgerInput::fields($this->ledgerParameters($request));

        return $this->success((object) $this->queries->journal($id, false, $bookId));
    }

    #[Route('/reports/{kind}', name: 'report', methods: ['GET'])]
    public function report(string $kind, Request $request): Response
    {
        $data = $this->queries->report($kind, $this->ledgerParameters($request), bookId: $this->authorizedBook());
        $context = array_intersect_key($data, array_flip(['cutoff', 'currency', 'scale', 'opening', 'totals']));

        return $this->success(new LedgerPage($data['rows'], $data['total'], $data['page'], $data['limit'], ['report' => ['kind' => $kind] + $context]));
    }

    #[Route('/reports/{kind}/csv', name: 'export', methods: ['GET'])]
    public function export(string $kind, Request $request): Response
    {
        return LedgerCsv::response($kind, $this->queries->report($kind, $this->ledgerParameters($request), true, $this->authorizedBook()));
    }

    private function authorizedBook(): int
    {
        try {
            $store = $this->storeForAuthorization();
            $this->denyAccessUnlessGranted('store:accounting:read', $store);
        } catch (AccessDeniedException) {
            throw new LedgerError('finance_access_required', 403);
        }

        return $this->workspace->forStore($store->getUuid());
    }

    protected function storeScopedFilter(Store $store): array { return ['book' => $this->workspace->forStore($store->getUuid())]; }
    protected function storeService(): StoreServiceInterface { return $this->stores; }
}
