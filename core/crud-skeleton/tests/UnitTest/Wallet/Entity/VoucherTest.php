<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Entity;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Voucher;
use PHPUnit\Framework\TestCase;

final class VoucherTest extends TestCase
{
    private function createVoucher(?string $reason = null): Voucher
    {
        $user = new User();
        $user->setEmail('v@t.com')->setUsername('v');
        $wallet = new Wallet($user, 'CNY');

        return new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            10000,
            'CNY',
            'ref-1',
            'admin',
            $reason,
        );
    }

    public function testMarkReversedPreservesCreationReason(): void
    {
        $voucher = $this->createVoucher('Original creation note');
        $voucher->markApplied('tx-1');

        $voucher->markReversed('rev-tx-1', 'Admin correction');

        self::assertSame(Voucher::STATUS_REVERSED, $voucher->getStatus());
        self::assertSame('Original creation note', $voucher->getReason());
        self::assertSame('Admin correction', $voucher->getMetadata()['reversalReason'] ?? null);
    }

    public function testMarkReversedRejectsNonAppliedStatus(): void
    {
        $voucher = $this->createVoucher();

        $this->expectException(\LogicException::class);
        $voucher->markReversed('rev-tx-1', 'reason');
    }

    public function testAddCommentAppendsImmutableNote(): void
    {
        $voucher = $this->createVoucher();

        $voucher->addComment('finance@admin', 'Ticket #123');

        self::assertCount(1, $voucher->getComments());
        $comment = $voucher->getComments()->first();
        self::assertSame('finance@admin', $comment->getActor());
        self::assertSame('Ticket #123', $comment->getText());
        self::assertInstanceOf(\DateTimeImmutable::class, $comment->getCreatedAt());
    }

    public function testCommentsAccumulateAppendOnly(): void
    {
        $voucher = $this->createVoucher();

        $voucher->addComment('admin', 'first');
        $voucher->addComment('finance', 'second');

        self::assertCount(2, $voucher->getComments());
        self::assertSame(['first', 'second'], array_map(
            static fn ($c) => $c->getText(),
            $voucher->getComments()->toArray(),
        ));
    }
}
