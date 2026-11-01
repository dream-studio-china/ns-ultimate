<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Store;

use App\Core\Controller\RestController;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuStoreContextResolver;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuPaymentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/huifu-payments', name: 'huifu-store-payments-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class HuifuPaymentController extends RestController
{
    use StoreScopedAuthorizationApiMixin;

    public function __construct(
        private readonly HuifuPaymentService $payments,
        private readonly HuifuStoreContextResolver $storeContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreServiceInterface $storeService,
    ) {
    }

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
        return 'huifu_payment';
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $this->authorizeStoreAction('create');
        $content = json_decode($request->getContent(), true) ?: [];
        $invoiceUuid = $content['invoiceUuid'] ?? null;
        if (!is_string($invoiceUuid) || $invoiceUuid === '') {
            return $this->warning('invoiceUuid is required.', 400, '', 400);
        }
        $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['uuid' => $invoiceUuid]);
        if (!$invoice instanceof Invoice) {
            return $this->warning('Invoice not found.', 404, '', 404);
        }
        try {
            $this->storeContext->assertInvoiceBelongsToStore($invoice, $this->storeForAuthorization()->getUuid());
            $result = $this->payments->start(
                $invoice,
                (string) ($content['channel'] ?? ''),
                [
                    'tradeType' => $content['tradeType'] ?? null,
                    'clientIp' => $request->getClientIp(),
                ],
            );

            return $this->success($result, 'Huifu payment started', 201);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $this->authorizeStoreAction('list');
        $attempts = $this->entityManager->getRepository(HuifuPaymentAttempt::class)
            ->findBy(['storeUuid' => $this->storeForAuthorization()->getUuid()], ['id' => 'DESC']);

        return $this->success($attempts);
    }
}
