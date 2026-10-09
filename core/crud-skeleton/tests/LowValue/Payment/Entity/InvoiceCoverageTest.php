<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Payment\Entity;


use PHPUnit\Framework\Attributes\Group;
use App\Payment\Entity\Invoice;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class InvoiceCoverageTest extends TestCase
{
    public function testPrePersistReinitializesCreatedAtWhenMissing(): void
    {
        $invoice = (new \ReflectionClass(Invoice::class))->newInstanceWithoutConstructor();

        $property = new \ReflectionProperty(Invoice::class, 'createdAt');
        self::assertFalse($property->isInitialized($invoice));

        $invoice->prePersist();

        self::assertTrue($property->isInitialized($invoice));
        self::assertInstanceOf(\DateTimeImmutable::class, $invoice->getCreatedAt());
    }
}
