<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Controller\App;

use App\Identity\Entity\User;
use App\Wallet\Controller\App\VoucherController;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Deposit\DepositServiceInterface;
use App\Wallet\Service\VoucherServiceInterface;
use App\Wallet\Service\Withdraw\WithdrawServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class VoucherControllerTest extends TestCase
{
    private VoucherServiceInterface $service;
    private VoucherRepository $voucherRepository;
    private DepositServiceInterface $depositService;
    private WithdrawServiceInterface $withdrawService;
    private WalletRepository $walletRepository;
    private VoucherController $controller;
    private User $user;

    protected function setUp(): void
    {
        $this->service = $this->createMock(VoucherServiceInterface::class);
        $this->voucherRepository = $this->createMock(VoucherRepository::class);
        $this->depositService = $this->createMock(DepositServiceInterface::class);
        $this->withdrawService = $this->createMock(WithdrawServiceInterface::class);
        $this->walletRepository = $this->createMock(WalletRepository::class);

        $this->user = new User();
        $this->user->setEmail('v@t.com')->setUsername('v');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($this->user, 77);

        $this->controller = new VoucherController(
            $this->service,
            $this->voucherRepository,
            $this->depositService,
            $this->withdrawService,
            $this->walletRepository,
        );
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            fn (string $id) => match ($id) {
                'security.token_storage' => $tokenStorage,
                default => null,
            }
        );

        $this->controller->setContainer($container);
        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    private function jsonRequest(string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function makeWallet(int $id, ?User $owner = null): Wallet
    {
        $wallet = new Wallet($owner ?? $this->user, 'CNY');
        $r = new \ReflectionProperty(Wallet::class, 'id');
        $r->setValue($wallet, $id);

        return $wallet;
    }

    private function makeAppliedVoucher(int $walletId, int $amount, ?User $owner = null): Voucher
    {
        $wallet = $this->makeWallet($walletId, $owner);
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            $amount,
            'CNY',
            'ref-1',
            'v',
        );
        $voucher->markApplied('tx-1');

        return $voucher;
    }

    private function makeAppliedDebitVoucher(int $walletId, int $amount, ?User $owner = null): Voucher
    {
        $wallet = $this->makeWallet($walletId, $owner);
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_DEBIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            $amount,
            'CNY',
            'ref-1',
            'v',
        );
        $voucher->markApplied('tx-1');

        return $voucher;
    }

    public function testDepositIntoOwnWallet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/deposit', [
            'walletId' => 5, 'amount' => 50000, 'currency' => 'CNY',
            'referenceId' => 'APP-DEP-1', 'reason' => 'self top-up', 'voucherType' => 'recharge',
        ]));
        $this->injectDependencies($requestStack);

        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $this->user));
        $voucher = $this->makeAppliedVoucher(5, 50000, $this->user);
        $this->depositService->method('deposit')
            ->with('recharge', 'APP-DEP-1', 5, 50000, 'CNY', 'APP-DEP-1', 'v', 'self top-up')
            ->willReturn($voucher);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Deposit completed', $body['message']);
    }

    public function testDepositRejectsManualVoucherType(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/deposit', [
            'walletId' => 5, 'amount' => 100, 'currency' => 'CNY',
            'referenceId' => 'r1', 'voucherType' => Voucher::VOUCHER_TYPE_MANUAL,
        ]));
        $this->injectDependencies($requestStack);

        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $this->user));
        $this->depositService->method('deposit')
            ->willThrowException(new AccessDeniedException('Manual voucher type is admin-only.'));

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('admin-only', $body['message']);
    }

    public function testDepositIntoForeignWalletIsForbidden(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/deposit', [
            'walletId' => 5, 'amount' => 100, 'currency' => 'CNY', 'referenceId' => 'r1', 'voucherType' => 'recharge',
        ]));
        $this->injectDependencies($requestStack);

        $other = new User();
        $other->setEmail('other@t.com')->setUsername('other');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($other, 88);
        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $other));

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Wallet not found', $body['message']);
    }

    #[Group('low-value')]
    public function testDepositRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/deposit', ['walletId' => 5]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('required', $body['message']);
    }

    public function testReverseOwnVoucher(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/uuid-1/reverse', [
            'reason' => 'self revert',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedVoucher(5, 30000, $this->user);
        $voucher->markReversed('rev-tx-1', 'self revert');
        $this->voucherRepository->method('findByUuid')->with('uuid-1')->willReturn($voucher);
        $this->depositService->method('reverse')->with('uuid-1', 'self revert')->willReturn($voucher);

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Deposit reversed', $body['message']);
    }

    public function testReverseForeignVoucherIsForbidden(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/uuid-1/reverse', []));
        $this->injectDependencies($requestStack);

        $other = new User();
        $other->setEmail('other@t.com')->setUsername('other');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($other, 88);
        $this->voucherRepository->method('findByUuid')->with('uuid-1')
            ->willReturn($this->makeAppliedVoucher(5, 30000, $other));

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Voucher not found', $body['message']);
    }

    public function testWithdrawFromOwnWallet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/withdraw', [
            'walletId' => 5, 'amount' => 30000, 'currency' => 'CNY',
            'referenceId' => 'APP-WD-1', 'reason' => 'self payout', 'voucherType' => 'payout',
        ]));
        $this->injectDependencies($requestStack);

        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $this->user));
        $voucher = $this->makeAppliedDebitVoucher(5, 30000, $this->user);
        $this->withdrawService->method('withdraw')
            ->with('payout', 'APP-WD-1', 5, 30000, 'CNY', 'APP-WD-1', 'v', 'self payout')
            ->willReturn($voucher);

        $response = $this->controller->withdrawAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Withdrawal completed', $body['message']);
    }

    public function testWithdrawRejectsManualVoucherType(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/withdraw', [
            'walletId' => 5, 'amount' => 100, 'currency' => 'CNY',
            'referenceId' => 'r1', 'voucherType' => Voucher::VOUCHER_TYPE_MANUAL,
        ]));
        $this->injectDependencies($requestStack);

        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $this->user));
        $this->withdrawService->method('withdraw')
            ->willThrowException(new AccessDeniedException('Manual voucher type is admin-only.'));

        $response = $this->controller->withdrawAction($requestStack->getCurrentRequest());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('admin-only', $body['message']);
    }

    public function testWithdrawFromForeignWalletIsForbidden(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/withdraw', [
            'walletId' => 5, 'amount' => 100, 'currency' => 'CNY', 'referenceId' => 'r1', 'voucherType' => 'payout',
        ]));
        $this->injectDependencies($requestStack);

        $other = new User();
        $other->setEmail('other@t.com')->setUsername('other');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($other, 88);
        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $other));

        $response = $this->controller->withdrawAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Wallet not found', $body['message']);
    }

    #[Group('low-value')]
    public function testWithdrawRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/vouchers/withdraw', ['walletId' => 5]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->withdrawAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('required', $body['message']);
    }
}
