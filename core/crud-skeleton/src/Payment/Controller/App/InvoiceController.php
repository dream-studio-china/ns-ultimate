<?php

declare(strict_types=1);

namespace App\Payment\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('/app/invoices', name: 'app-invoices-')]
#[IsGranted('ROLE_USER')]
class InvoiceController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(protected readonly InvoiceServiceInterface $service)
    {
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $user = $this->getUser();
        return $user ? ['payer' => $user] : ['id' => -1];
    }

    #[Route('/{id<\d+>}/pay/{payment}', name: 'pay', methods: ['POST'])]
    public function payAction(Request $request, int $id, string $payment): Response
    {
        $user = $this->getUser();
        $invoice = $this->service->get(['id' => $id]);
        if (!$invoice instanceof Invoice || !$user instanceof User || $invoice->getPayer()?->getId() !== $user->getId()) {
            return $this->warning('Invoice not found.', 404, '', 404);
        }

        try {
            $options = json_decode($request->getContent(), true) ?: [];
            return $this->success($this->service->pay($invoice, $payment, $options), 'Payment started');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }
}
