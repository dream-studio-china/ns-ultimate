<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Service\OrderCounterService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Lists only the current customer's counter bindings for one order. */
#[Route('/app/orders/{orderUuid}/counters', name: 'app-order-counters-', requirements: ['orderUuid' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class OrderCountersByOrderController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly OrderCounterService $service,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /** @return array<string, string> */
    protected function commonFilter(): array
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['uuid' => $this->orderUuid()]);
        $user = $this->security->getUser();
        if (!$order instanceof Order || !$user instanceof User || $order->getUser()?->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Order not found.');
        }

        return ['tradeOrderUuid' => $order->getUuid()];
    }

    private function orderUuid(): string
    {
        $value = $this->getRequestStack()->getCurrentRequest()?->attributes->get('orderUuid');
        if (!is_string($value) || $value === '') {
            throw new NotFoundHttpException('Order not found.');
        }

        return $value;
    }
}
