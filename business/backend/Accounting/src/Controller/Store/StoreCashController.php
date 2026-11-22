<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller\Store;

use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\StoreCashRegister;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/store/{scopeId}/cash-register', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
final class StoreCashController extends RestController
{
    use StoreScopedAuthorizationApiMixin;

    public function __construct(private readonly StoreServiceInterface $stores, private readonly StoreCashRegister $cash) {}

    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function categories(): Response
    {
        return $this->respond(function (Store $store): array {
            $this->authorizeApiAction('list');
            return ['categories' => $this->cash->categories($store)];
        });
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return $this->respond(function (Store $store) use ($request): array {
            $this->authorizeApiAction('list');
            $parameters = $request->query->all();
            unset($parameters['_locale']);
            LedgerInput::fields($parameters, 'month', 'page');
            $month = $parameters['month'] ?? null;
            if ($month !== null && (!is_string($month) || !preg_match('/\A[1-9][0-9]{3}-[0-9]{2}\z/', $month))) {
                throw new LedgerError('invalid_date');
            }
            $page = LedgerInput::page($parameters['page'] ?? '1');

            return $this->cash->month($store, $month, $page);
        });
    }

    #[Route('', name: 'record', methods: ['POST'])]
    public function record(Request $request): Response
    {
        return $this->respond(function (Store $store, User $user) use ($request): array {
            $this->authorizeApiAction('list');
            $this->authorizeApiAction('create');
            if ($request->getContentTypeFormat() !== 'json') {
                throw new LedgerError('json_required', 415);
            }
            if (strlen($request->getContent()) > 8192) {
                throw new LedgerError('body_too_large', 413);
            }
            try {
                $object = json_decode($request->getContent(), false, 8, JSON_THROW_ON_ERROR);
                if (!$object instanceof \stdClass || (property_exists($object, 'metadata') && !$object->metadata instanceof \stdClass)) {
                    throw new LedgerError('invalid_body');
                }
                $payload = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new LedgerError('invalid_body');
            }

            return $this->cash->record($store, $user->getUuid(), $payload);
        });
    }

    private function respond(callable $operation): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new LedgerError('authentication_required', 401);
        }
        try {
            $store = $this->storeForAuthorization();

            // Return an object so the Core reply helper does not paginate the monthly envelope.
            return $this->success((object) $operation($store, $user));
        } catch (AccessDeniedException) {
            throw new LedgerError('finance_access_required', 403);
        }
    }

    protected function storeAuthorizationResource(): string
    {
        return 'cash_entry';
    }

    /** @return array{storeUuid: string} */
    protected function storeScopedFilter(Store $store): array { return ['storeUuid' => $store->getUuid()]; }
    protected function storeService(): StoreServiceInterface { return $this->stores; }
}
