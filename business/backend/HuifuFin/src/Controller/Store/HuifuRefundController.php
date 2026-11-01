<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Store;

use App\Core\Controller\RestController;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuStoreContextResolver;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/huifu-refunds', name: 'huifu-store-refunds-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class HuifuRefundController extends RestController
{
    use StoreScopedAuthorizationApiMixin;

    public function __construct(
        private readonly HuifuRefundWorkflow $refunds,
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
        return 'huifu_refund';
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $this->authorizeStoreAction('create');
        $content = json_decode($request->getContent(), true) ?: [];
        $invoiceUuid = $content['invoiceUuid'] ?? null;
        $reason = $content['reason'] ?? null;
        if (!is_string($invoiceUuid) || $invoiceUuid === '') {
            return $this->warning('invoiceUuid is required.', 400, '', 400);
        }
        if (!is_string($reason) || trim($reason) === '') {
            return $this->warning('reason is required.', 400, '', 400);
        }
        $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['uuid' => $invoiceUuid]);
        if (!$invoice instanceof Invoice) {
            return $this->warning('Invoice not found.', 404, '', 404);
        }
        try {
            $this->storeContext->assertInvoiceBelongsToStore($invoice, $this->storeForAuthorization()->getUuid());
            $refund = $this->refunds->request($invoice, $this->parseAmount($content['amount'] ?? 0), trim($reason), $content);
            $status = $refund->getStatus() === HuifuRefund::STATUS_SUCCEEDED ? 200 : 202;

            return $this->success($refund, $status === 202 ? 'Huifu refund is processing' : 'Huifu refund succeeded', $status);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $this->authorizeStoreAction('list');
        $refunds = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(HuifuRefund::class, 'r')
            ->join('r.attempt', 'a')
            ->where('a.storeUuid = :storeUuid')
            ->setParameter('storeUuid', $this->storeForAuthorization()->getUuid())
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->success($refunds);
    }

    private function parseAmount(mixed $amount): int
    {
        if (is_int($amount)) {
            return $amount;
        }
        if (is_float($amount) || (is_string($amount) && str_contains($amount, '.'))) {
            return (int) round(((float) $amount) * 100);
        }
        if (is_string($amount) && ctype_digit($amount)) {
            return (int) $amount;
        }

        return 0;
    }
}
