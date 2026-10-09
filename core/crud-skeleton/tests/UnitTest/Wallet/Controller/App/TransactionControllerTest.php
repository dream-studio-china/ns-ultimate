<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Controller\App;

use App\Identity\Entity\User;
use App\Wallet\Controller\App\TransactionController;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Service\TransactionServiceInterface;
use App\Wallet\Service\Transfer\TransferResult;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class TransactionControllerTest extends TestCase
{
    private TransactionServiceInterface $transactionService;
    private TransactionRepository $transactionRepository;
    private TransferServiceInterface $transferService;
    private WalletRepository $walletRepository;
    private TransactionController $controller;
    private User $user;

    protected function setUp(): void
    {
        $this->transactionService = $this->createMock(TransactionServiceInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->transferService = $this->createMock(TransferServiceInterface::class);
        $this->walletRepository = $this->createMock(WalletRepository::class);

        $this->user = new User();
        $this->user->setEmail('tx@t.com')->setUsername('tx');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($this->user, 99);

        $this->controller = new TransactionController(
            $this->transactionService,
            $this->transactionRepository,
            $this->transferService,
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

    private function stubLifecycle(): void
    {
        $this->transactionService->method('wrapInTransaction')->willReturnCallback(
            fn($cb) => $cb(null)
        );
        $this->transactionService->method('new')->willReturn(
            $this->makeTransferTransaction(0, 0, 7, 8)
        );
        $this->transactionService->method('update')->willReturnArgument(0);
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

    private function makeTransferTransaction(int $id, int $amount, int $fromWalletId, int $toWalletId): Transaction
    {
        $tx = new Transaction('uuid-' . $id, $amount, Transaction::TYPE_TRANSFER);
        $tx->setFromWallet($this->makeWallet($fromWalletId));
        $tx->setToWallet($this->makeWallet($toWalletId));
        $tx->markCompleted();
        $r = new \ReflectionProperty(Transaction::class, 'id');
        $r->setValue($tx, $id);

        return $tx;
    }

    public function testCreateTransferFromOwnWallet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/transactions', [
            'fromWalletId' => 7, 'toWalletId' => 8, 'amount' => 30000, 'description' => 'my transfer',
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->walletRepository->method('find')->with(7)->willReturn($this->makeWallet(7, $this->user));
        $tx = $this->makeTransferTransaction(42, 30000, 7, 8);
        $this->transferService->method('transfer')
            ->with(7, 8, 30000, null, 'my transfer')
            ->willReturn(new TransferResult($tx, 70000, 30000));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(7, $body['data']['fromWalletId']);
        self::assertSame(30000, $body['data']['amount']);
        self::assertSame(70000, $body['data']['fromWalletBalanceAfter']);
    }

    public function testCreateTransferFromForeignWalletIsForbidden(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/transactions', [
            'fromWalletId' => 7, 'toWalletId' => 8, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $other = new User();
        $other->setEmail('other@t.com')->setUsername('other');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($other, 100);
        $this->walletRepository->method('find')->with(7)->willReturn($this->makeWallet(7, $other));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Source wallet not found', $body['message']);
    }

    public function testCreateTransferForeignWalletMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/transactions', [
            'fromWalletId' => 999, 'toWalletId' => 8, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->walletRepository->method('find')->with(999)->willReturn(null);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
    }

    #[Group('low-value')]
    public function testCreateTransferRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/transactions', ['fromWalletId' => 7]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
    }

    #[Group('low-value')]
    public function testCreateTransferInsufficientFundsMapsTo400(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/transactions', [
            'fromWalletId' => 7, 'toWalletId' => 8, 'amount' => 999999,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->walletRepository->method('find')->with(7)->willReturn($this->makeWallet(7, $this->user));
        $this->transferService->method('transfer')->willThrowException(
            new InsufficientFundsException(7, 100, 999999)
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Insufficient funds', $body['message']);
    }
}
