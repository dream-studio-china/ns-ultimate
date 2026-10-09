<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Entity\Voucher;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Service\Deposit\DepositServiceInterface;
use App\Wallet\Service\VoucherServiceInterface;
use App\Wallet\Service\Withdraw\WithdrawServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Voucher resource (admin): list/detail all vouchers, deposit (voucher-backed
 * manual funding into any wallet), withdraw (manual debit out of any wallet)
 * and reverse any voucher. Update/Delete mixins are intentionally omitted: the
 * ledger is append-only.
 */
#[Route('/manage/vouchers', name: 'manage-vouchers-')]
#[IsGranted('ROLE_ADMIN')]
class VoucherController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly VoucherServiceInterface $service,
        private readonly DepositServiceInterface $depositService,
        private readonly WithdrawServiceInterface $withdrawService,
        private readonly VoucherRepository $voucherRepository,
    ) {}

    #[Route('/deposit', name: 'deposit', methods: ['POST'])]
    public function depositAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $walletId = (int) ($content['walletId'] ?? 0);
        $amount = (int) ($content['amount'] ?? 0);
        $currency = (string) ($content['currency'] ?? '');
        $referenceId = (string) ($content['referenceId'] ?? '');
        $voucherType = (string) ($content['voucherType'] ?? Voucher::VOUCHER_TYPE_MANUAL);
        $voucherId = (string) ($content['voucherId'] ?? $referenceId);
        $reason = isset($content['reason']) ? (string) $content['reason'] : null;

        if ($walletId <= 0 || $amount <= 0 || $currency === '' || $referenceId === '') {
            return $this->warning('walletId, amount, currency, and referenceId are required', 400, '', 400);
        }

        $user = $this->getUser();
        $actorName = $user instanceof User ? $user->getUsername() : 'system';

        try {
            $voucher = $this->depositService->deposit(
                $voucherType,
                $voucherId,
                $walletId,
                $amount,
                $currency,
                $referenceId,
                $actorName,
                $reason,
            );

            return $this->success($voucher, 'Deposit completed', 201);
        } catch (AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 403, '', 403);
        } catch (WalletFrozenException $e) {
            return $this->warning($e->getMessage(), 403, '', 403);
        } catch (InsufficientFundsException $e) {
            return $this->warning($e->getMessage(), 402, '', 402);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'not found') ? 404 : 500;
            return $this->warning($e->getMessage() ?: 'Deposit failed', $status, '', $status);
        }
    }

    #[Route('/withdraw', name: 'withdraw', methods: ['POST'])]
    public function withdrawAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $walletId = (int) ($content['walletId'] ?? 0);
        $amount = (int) ($content['amount'] ?? 0);
        $currency = (string) ($content['currency'] ?? '');
        $referenceId = (string) ($content['referenceId'] ?? '');
        $voucherType = (string) ($content['voucherType'] ?? Voucher::VOUCHER_TYPE_MANUAL);
        $voucherId = (string) ($content['voucherId'] ?? $referenceId);
        $reason = isset($content['reason']) ? (string) $content['reason'] : null;

        if ($walletId <= 0 || $amount <= 0 || $currency === '' || $referenceId === '') {
            return $this->warning('walletId, amount, currency, and referenceId are required', 400, '', 400);
        }

        $user = $this->getUser();
        $actorName = $user instanceof User ? $user->getUsername() : 'system';

        try {
            $voucher = $this->withdrawService->withdraw(
                $voucherType,
                $voucherId,
                $walletId,
                $amount,
                $currency,
                $referenceId,
                $actorName,
                $reason,
            );

            return $this->success($voucher, 'Withdrawal completed', 201);
        } catch (AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 403, '', 403);
        } catch (WalletFrozenException $e) {
            return $this->warning($e->getMessage(), 403, '', 403);
        } catch (InsufficientFundsException $e) {
            return $this->warning($e->getMessage(), 402, '', 402);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'not found') ? 404 : 500;
            return $this->warning($e->getMessage() ?: 'Withdrawal failed', $status, '', $status);
        }
    }

    #[Route('/{uuid}/reverse', name: 'reverse', methods: ['POST'])]
    public function reverseAction(string $uuid, Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];
        $reason = (string) ($content['reason'] ?? 'Voucher reversed');

        try {
            $voucher = $this->voucherRepository->findByUuid($uuid);
            if (!$voucher instanceof Voucher) {
                throw new \RuntimeException(sprintf('Voucher "%s" not found.', $uuid));
            }

            $voucher = $voucher->getDirection() === Voucher::DIRECTION_CREDIT
                ? $this->depositService->reverse($uuid, $reason)
                : $this->withdrawService->reverse($uuid, $reason);

            $message = $voucher->getDirection() === Voucher::DIRECTION_CREDIT
                ? 'Deposit reversed'
                : 'Withdrawal reversed';

            return $this->success($voucher, $message, 200);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\LogicException $e) {
            return $this->warning($e->getMessage(), 409, '', 409);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'not found') ? 404 : 500;
            return $this->warning($e->getMessage() ?: 'Reversal failed', $status, '', $status);
        }
    }
}
