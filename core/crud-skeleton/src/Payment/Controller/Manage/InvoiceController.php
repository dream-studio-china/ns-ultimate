<?php

declare(strict_types=1);

namespace App\Payment\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/manage/invoices', name: 'manage-invoices-')]
#[IsGranted('ROLE_ADMIN')]
class InvoiceController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly InvoiceServiceInterface $service,
        private readonly EntityManagerInterface $entityManager,
        #[Target('state_machine.invoice')]
        protected readonly WorkflowInterface $workflow,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];
        foreach (['sourceType', 'sourceId', 'scene', 'amount'] as $field) {
            if (!array_key_exists($field, $content)) {
                return $this->warning("{$field} is required.", 400, '', 400);
            }
        }

        try {
            $payer = null;
            if (!empty($content['payer'])) {
                $payer = $this->entityManager->getRepository(User::class)->find((int) $content['payer']);
            }

            $invoice = $this->service->createInvoice(new CreateInvoiceRequest(
                sourceType: (string) $content['sourceType'],
                sourceId: (string) $content['sourceId'],
                scene: (string) $content['scene'],
                amount: self::parseAmount($content['amount']),
                currency: (string) ($content['currency'] ?? 'CNY'),
                payer: $payer,
                subject: $content['subject'] ?? null,
                description: $content['description'] ?? null,
                extraData: $content['extraData'] ?? [],
            ));

            return $this->success($invoice, 'SUCCESS', 201);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/pay/{payment}', name: 'pay', methods: ['POST'])]
    public function payAction(Request $request, int $id, string $payment): Response
    {
        $invoice = $this->service->get(['id' => $id]);
        if (!$invoice instanceof Invoice) {
            return $this->warning('Invoice not found.', 404, '', 404);
        }

        try {
            $options = json_decode($request->getContent(), true) ?: [];
            return $this->success($this->service->pay($invoice, $payment, $options), 'Payment started');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancelAction(Request $request, int $id): Response
    {
        $invoice = $this->service->get(['id' => $id]);
        if (!$invoice instanceof Invoice) {
            return $this->warning('Invoice not found.', 404, '', 404);
        }

        try {
            $content = json_decode($request->getContent(), true) ?: [];
            return $this->success($this->service->cancel($invoice, $content['reason'] ?? null), 'Invoice cancelled');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/refund', name: 'refund', methods: ['POST'])]
    public function refundAction(Request $request, int $id): Response
    {
        $invoice = $this->service->get(['id' => $id]);
        if (!$invoice instanceof Invoice) {
            return $this->warning('Invoice not found.', 404, '', 404);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        if (empty($content['amount']) || empty($content['reason'])) {
            return $this->warning('amount and reason are required.', 400, '', 400);
        }

        try {
            return $this->success(
                $this->service->refund($invoice, self::parseAmount($content['amount']), (string) $content['reason'], $content),
                'Refund processed',
            );
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/transitions', name: 'transitions', methods: ['GET'])]
    public function transitionsAction(int $id): Response
    {
        $invoice = $this->service->get(['id' => $id]);
        if (!$invoice instanceof Invoice) {
            return $this->warning('Invoice not found.', 404, '', 404);
        }

        return $this->success($this->workflow->getEnabledTransitions($invoice));
    }

    private static function parseAmount(mixed $amount): int
    {
        return is_float($amount) || (is_string($amount) && str_contains($amount, '.'))
            ? (int) round(((float) $amount) * 100)
            : (int) $amount;
    }
}
