<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Controller\Manage;

use App\Identity\Entity\User;
use App\Wallet\Controller\Manage\VoucherController;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\Wallet;
use App\Wallet\Service\Deposit\DepositServiceInterface;
use App\Wallet\Service\VoucherServiceInterface;
use App\Wallet\Service\Withdraw\WithdrawServiceInterface;
use App\Wallet\Repository\VoucherRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class VoucherControllerTest extends TestCase
{
    private VoucherServiceInterface $service;
    private DepositServiceInterface $depositService;
    private WithdrawServiceInterface $withdrawService;
    private VoucherRepository $voucherRepository;
    private VoucherController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(VoucherServiceInterface::class);
        $this->depositService = $this->createMock(DepositServiceInterface::class);
        $this->withdrawService = $this->createMock(WithdrawServiceInterface::class);
        $this->voucherRepository = $this->createMock(VoucherRepository::class);
        $this->controller = new VoucherController(
            $this->service,
            $this->depositService,
            $this->withdrawService,
            $this->voucherRepository,
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

        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
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

    private function makeAppliedVoucher(int $amount): Voucher
    {
        $user = new User();
        $user->setEmail('m@t.com')->setUsername('m');
        $wallet = new Wallet($user, 'CNY');
        $r = new \ReflectionProperty(Wallet::class, 'id');
        $r->setValue($wallet, 9);

        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            $amount,
            'CNY',
            'ref-1',
            'admin',
        );
        $voucher->markApplied('tx-1');

        return $voucher;
    }

    private function makeAppliedDebitVoucher(int $amount): Voucher
    {
        $user = new User();
        $user->setEmail('m@t.com')->setUsername('m');
        $wallet = new Wallet($user, 'CNY');
        $r = new \ReflectionProperty(Wallet::class, 'id');
        $r->setValue($wallet, 9);

        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_DEBIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            $amount,
            'CNY',
            'ref-1',
            'admin',
        );
        $voucher->markApplied('tx-1');

        return $voucher;
    }

    public function testDepositIntoAnyWallet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/deposit', [
            'walletId' => 9, 'amount' => 50000, 'currency' => 'CNY', 'referenceId' => 'DEP-1',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedVoucher(50000);
        $this->depositService->method('deposit')
            ->with(Voucher::VOUCHER_TYPE_MANUAL, 'DEP-1', 9, 50000, 'CNY', 'DEP-1', 'system', null)
            ->willReturn($voucher);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Deposit completed', $body['message']);
    }

    public function testDepositRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/deposit', ['walletId' => 9]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('required', $body['message']);
    }

    public function testReverseVoucher(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/uuid-1/reverse', [
            'reason' => 'admin revert',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedVoucher(30000);
        $voucher->markReversed('rev-tx-1', 'admin revert');
        $this->voucherRepository->method('findByUuid')->with('uuid-1')->willReturn($voucher);
        $this->depositService->method('reverse')->with('uuid-1', 'admin revert')->willReturn($voucher);

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Deposit reversed', $body['message']);
    }

    public function testReverseWithdrawalVoucher(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/uuid-1/reverse', [
            'reason' => 'admin revert',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedDebitVoucher(30000);
        $voucher->markReversed('rev-tx-1', 'admin revert');
        $this->voucherRepository->method('findByUuid')->with('uuid-1')->willReturn($voucher);
        $this->withdrawService->method('reverse')->with('uuid-1', 'admin revert')->willReturn($voucher);

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Withdrawal reversed', $body['message']);
    }

    public function testReverseVoucherNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/missing/reverse', []));
        $this->injectDependencies($requestStack);

        $this->voucherRepository->method('findByUuid')->with('missing')->willReturn(null);

        $response = $this->controller->reverseAction('missing', $requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testWithdrawFromAnyWallet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/withdraw', [
            'walletId' => 9, 'amount' => 30000, 'currency' => 'CNY', 'referenceId' => 'WD-1',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedDebitVoucher(30000);
        $this->withdrawService->method('withdraw')
            ->with(Voucher::VOUCHER_TYPE_MANUAL, 'WD-1', 9, 30000, 'CNY', 'WD-1', 'system', null)
            ->willReturn($voucher);

        $response = $this->controller->withdrawAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Withdrawal completed', $body['message']);
    }

    public function testWithdrawRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/withdraw', ['walletId' => 9]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->withdrawAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('required', $body['message']);
    }

    public function testDepositWithCustomVoucherType(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/deposit', [
            'walletId' => 9, 'amount' => 50000, 'currency' => 'CNY',
            'referenceId' => 'DEP-2', 'voucherType' => 'bonus',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedVoucher(50000);
        $this->depositService->method('deposit')
            ->with('bonus', 'DEP-2', 9, 50000, 'CNY', 'DEP-2', 'system', null)
            ->willReturn($voucher);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
    }

    public function testWithdrawWithCustomVoucherType(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/vouchers/withdraw', [
            'walletId' => 9, 'amount' => 30000, 'currency' => 'CNY',
            'referenceId' => 'WD-2', 'voucherType' => 'payout',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedDebitVoucher(30000);
        $this->withdrawService->method('withdraw')
            ->with('payout', 'WD-2', 9, 30000, 'CNY', 'WD-2', 'system', null)
            ->willReturn($voucher);

        $response = $this->controller->withdrawAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
    }
}
