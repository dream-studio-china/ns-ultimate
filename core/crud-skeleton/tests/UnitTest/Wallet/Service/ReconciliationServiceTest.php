<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Voucher;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Service\ReconciliationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ReconciliationServiceTest extends TestCase
{
    private VoucherRepository $repo;
    private ReconciliationService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(VoucherRepository::class);
        $this->service = new ReconciliationService($this->repo);
    }

    private function createVoucher(
        int $amount,
        string $suffix,
        string $fundSource = Voucher::FUND_SOURCE_EXTERNAL,
        string $direction = Voucher::DIRECTION_CREDIT
    ): Voucher {
        $user = new User();
        $user->setEmail('rec@t.com')->setUsername('rec');
        $wallet = new Wallet($user, 'CNY');

        $voucher = new Voucher(
            $wallet,
            $direction,
            $fundSource,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-' . $suffix,
            $amount,
            'CNY',
            'ref-' . $suffix,
            'admin',
        );
        $voucher->markApplied('tx-' . $suffix);

        return $voucher;
    }

    // ──────────────── listBoundaryVouchers ────────────────

    public function testListBoundaryVouchersSerializesAppliedVouchers(): void
    {
        $voucher = $this->createVoucher(50000, 'v1');
        $this->repo->method('findForReconciliation')
            ->with('CNY', Voucher::FUND_SOURCE_EXTERNAL, null, null)
            ->willReturn([$voucher]);

        $rows = $this->service->listBoundaryVouchers('CNY', Voucher::FUND_SOURCE_EXTERNAL);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($voucher->getUuid(), $row['uuid']);
        self::assertSame('credit', $row['direction']);
        self::assertSame('external', $row['fundSource']);
        self::assertSame('manual', $row['voucherType']);
        self::assertSame(50000, $row['amount']);
        self::assertSame('CNY', $row['currency']);
        self::assertSame('applied', $row['status']);
        self::assertSame('admin', $row['createdBy']);
        self::assertSame('ref-v1', $row['referenceId']);
        self::assertNotNull($row['walletTransactionId']);
        self::assertNotNull($row['appliedAt']);
    }

    public function testListBoundaryVouchersEmpty(): void
    {
        $this->repo->method('findForReconciliation')->willReturn([]);

        $rows = $this->service->listBoundaryVouchers('CNY');

        self::assertSame([], $rows);
    }

    public function testListBoundaryVouchersMultipleMixedSources(): void
    {
        $external = $this->createVoucher(10000, 'ext');
        $internal = $this->createVoucher(20000, 'int', Voucher::FUND_SOURCE_INTERNAL);
        $this->repo->method('findForReconciliation')->willReturn([$external, $internal]);

        $rows = $this->service->listBoundaryVouchers('CNY');

        self::assertCount(2, $rows);
        self::assertSame(['external', 'internal'], array_column($rows, 'fundSource'));
    }

    public function testListBoundaryVouchersPassesDateRange(): void
    {
        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');
        $this->repo->expects(self::once())->method('findForReconciliation')
            ->with('CNY', null, $from, $to)
            ->willReturn([]);

        $this->service->listBoundaryVouchers('CNY', null, $from, $to);
    }

    public function testListBoundaryVouchersSerializesDebitVoucher(): void
    {
        $debit = $this->createVoucher(30000, 'wd', Voucher::FUND_SOURCE_EXTERNAL, Voucher::DIRECTION_DEBIT);
        $debit->markReversed('rev-tx-wd', 'cancelled');
        $this->repo->method('findForReconciliation')->willReturn([$debit]);

        $rows = $this->service->listBoundaryVouchers('CNY');

        self::assertCount(1, $rows);
        self::assertSame('debit', $rows[0]['direction']);
        self::assertSame('reversed', $rows[0]['status']);
        self::assertSame('rev-tx-wd', $rows[0]['reversalTransactionId']);
        self::assertNotNull($rows[0]['reversedAt'] ?? null);
    }

    public function testListBoundaryVouchersRejectsEmptyCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency is required');
        $this->service->listBoundaryVouchers('');
    }

    // ──────────────── reconcileAgainstExternal ────────────────

    public function testReconcileAllMatched(): void
    {
        $voucher = $this->createVoucher(50000, 'm1');
        $this->repo->method('findForReconciliation')->willReturn([$voucher]);

        $result = $this->service->reconcileAgainstExternal('CNY', [[
            'referenceId' => 'ref-m1',
            'amount' => 50000,
            'direction' => 'credit',
        ]]);

        self::assertSame('ok', $result['status']);
        self::assertSame('CNY', $result['currency']);
        self::assertCount(1, $result['matched']);
        self::assertSame([], $result['unmatchedVouchers']);
        self::assertSame('ref-m1', $result['matched'][0]['voucher']['referenceId']);
    }

    public function testReconcileFlagsVoucherWithoutExternalLine(): void
    {
        $voucher = $this->createVoucher(50000, 'orphan');
        $this->repo->method('findForReconciliation')->willReturn([$voucher]);

        $result = $this->service->reconcileAgainstExternal('CNY', []);

        self::assertSame('needs_reconcile', $result['status']);
        self::assertCount(0, $result['matched']);
        self::assertCount(1, $result['unmatchedVouchers']);
        self::assertSame('ref-orphan', $result['unmatchedVouchers'][0]['referenceId']);
    }

    public function testReconcileMixedMatchedAndUnmatched(): void
    {
        $matched = $this->createVoucher(10000, 'ok');
        $orphan = $this->createVoucher(20000, 'missing');
        $this->repo->method('findForReconciliation')->willReturn([$matched, $orphan]);

        $result = $this->service->reconcileAgainstExternal('CNY', [[
            'referenceId' => 'ref-ok',
            'amount' => 10000,
            'direction' => 'credit',
        ]]);

        self::assertSame('needs_reconcile', $result['status']);
        self::assertCount(1, $result['matched']);
        self::assertSame('ref-ok', $result['matched'][0]['voucher']['referenceId']);
        self::assertCount(1, $result['unmatchedVouchers']);
        self::assertSame('ref-missing', $result['unmatchedVouchers'][0]['referenceId']);
    }

    public function testReconcileAmountMismatchNotMatched(): void
    {
        $voucher = $this->createVoucher(50000, 'amt');
        $this->repo->method('findForReconciliation')->willReturn([$voucher]);

        // Same referenceId but wrong amount — must NOT match, flagged as problem.
        $result = $this->service->reconcileAgainstExternal('CNY', [[
            'referenceId' => 'ref-amt',
            'amount' => 40000,
            'direction' => 'credit',
        ]]);

        self::assertSame('needs_reconcile', $result['status']);
        self::assertCount(0, $result['matched']);
        self::assertCount(1, $result['unmatchedVouchers']);
    }

    public function testReconcileDirectionMismatchNotMatched(): void
    {
        $voucher = $this->createVoucher(50000, 'dir');
        $this->repo->method('findForReconciliation')->willReturn([$voucher]);

        // Same referenceId/amount but wrong direction — must NOT match.
        $result = $this->service->reconcileAgainstExternal('CNY', [[
            'referenceId' => 'ref-dir',
            'amount' => 50000,
            'direction' => 'debit',
        ]]);

        self::assertSame('needs_reconcile', $result['status']);
        self::assertCount(0, $result['matched']);
        self::assertCount(1, $result['unmatchedVouchers']);
    }

    public function testReconcileEmptyCurrencyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency is required');
        $this->service->reconcileAgainstExternal('', []);
    }
}
