<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Controller\Manage;

use App\Identity\Entity\User;
use App\Wallet\Controller\Manage\TransactionController;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Service\TransactionServiceInterface;
use App\Wallet\Service\Transfer\TransferResult;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class TransactionControllerTest extends TestCase
{
    private TransactionServiceInterface $transactionService;
    private TransferServiceInterface $transferService;
    private TransactionController $controller;

    protected function setUp(): void
    {
        $this->transactionService = $this->createMock(TransactionServiceInterface::class);
        $this->transferService = $this->createMock(TransferServiceInterface::class);
        $this->controller = new TransactionController($this->transactionService, $this->transferService);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    /** Drive the mixin's transaction lifecycle so processItem runs. */
    private function stubLifecycle(): void
    {
        $this->transactionService->method('wrapInTransaction')->willReturnCallback(
            fn($cb) => $cb(null)
        );
        $this->transactionService->method('new')->willReturn(
            $this->makeTransferTransaction(0, 0, 1, 2)
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

    private function makeWallet(int $id): Wallet
    {
        $user = new User();
        $user->setEmail("w$id@t.com")->setUsername("w$id");
        $wallet = new Wallet($user, 'CNY');
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
        $ref = new \ReflectionProperty(Transaction::class, 'id');
        $ref->setValue($tx, $id);

        return $tx;
    }

    // ──────────────── createAction: guard clauses ────────────────

    #[Group('low-value')]
    public function testCreateActionRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', ['fromWalletId' => 1]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('ToWalletId is required', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionRejectsNegativeAmount(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => -50,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(
            new \InvalidArgumentException('Transfer amount must be positive')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Transfer amount must be positive', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionRejectsZeroAmount(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 0,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(
            new \InvalidArgumentException('Transfer amount must be positive')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Transfer amount must be positive', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionRejectsInvalidJson(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('/api/v1/manage/transactions', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{not json');
        $requestStack->push($request);
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
    }

    // ──────────────── createAction: success path ────────────────

    #[Group('low-value')]
    public function testCreateActionSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => '7', 'toWalletId' => '8', 'amount' => '30000',
            'referenceId' => 'REF-1', 'description' => 'payout',
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $tx = $this->makeTransferTransaction(42, 30000, 7, 8);
        $this->transferService->method('transfer')
            ->with(7, 8, 30000, 'REF-1', 'payout')
            ->willReturn(new TransferResult($tx, 70000, 30000));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('SUCCESS', $body['message']);
        self::assertSame(42, $body['data']['transactionId']);
        self::assertSame('uuid-42', $body['data']['uuid']);
        self::assertSame(7, $body['data']['fromWalletId']);
        self::assertSame(8, $body['data']['toWalletId']);
        self::assertSame(30000, $body['data']['amount']);
        self::assertEquals(300.0, $body['data']['amountFloat']);
        self::assertSame('completed', $body['data']['status']);
        self::assertSame(70000, $body['data']['fromWalletBalanceAfter']);
        self::assertSame(30000, $body['data']['toWalletBalanceAfter']);
        self::assertIsArray($body['data']['createdAt']);
    }

    // ──────────────── createAction: exception branches ────────────────

    #[Group('low-value')]
    public function testCreateActionInsufficientFundsMapsTo400(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 999999,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(
            new InsufficientFundsException(1, 100, 999999)
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Insufficient funds', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionWalletFrozenMapsTo400(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(new WalletFrozenException(1));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('frozen', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionSameWalletMapsTo400(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 1, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(new SameWalletTransferException());

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Cannot transfer to the same wallet', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionInvalidArgument(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(
            new \InvalidArgumentException('Invalid reference id')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Invalid reference id', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionRuntimeNotFoundMapsTo404(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 999, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(
            new \RuntimeException('Source wallet #999 not found')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Source wallet #999 not found', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionRuntimeErrorMapsTo500(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $this->transferService->method('transfer')->willThrowException(
            new \RuntimeException('Database connection lost')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Database connection lost', $body['message']);
    }

    public function testCreateActionIdempotentReplayEchoesStoredTransaction(): void
    {
        // On replay (existing referenceId) the response must reflect the STORED
        // transaction, not the request amount.
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 99999, 'referenceId' => 'REF-REPLAY',
        ]));
        $this->injectDependencies($requestStack);
        $this->stubLifecycle();

        $stored = $this->makeTransferTransaction(7, 50000, 1, 2);
        $this->transferService->method('transfer')
            ->with(1, 2, 99999, 'REF-REPLAY', null)
            ->willReturn(new TransferResult($stored, 50000, 0));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(50000, $body['data']['amount']);
        self::assertEquals(500.0, $body['data']['amountFloat']);
    }
}
