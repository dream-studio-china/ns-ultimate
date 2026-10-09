<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Entity;

use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PromotionTest extends TestCase
{
    #[Group('low-value')]
    public function testConstructorInitializesCoreFields(): void
    {
        $p = new Promotion();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $p->getUuid()
        );
        self::assertSame('', $p->getName());
        self::assertNull($p->getDescription());
        self::assertNull($p->getTemplate());
        self::assertSame('', $p->getStoreCode());
        self::assertFalse($p->isEnabled());
        self::assertNull($p->getStartTime());
        self::assertNull($p->getEndTime());
        self::assertNull($p->getConfig());
        self::assertSame(Promotion::CONFLICT_STACKABLE, $p->getConflictMode());
        self::assertNull($p->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $p->getCreatedAt());
        self::assertNull($p->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testNameAccessors(): void
    {
        $p = new Promotion();
        $p->setName('Summer Sale');

        self::assertSame('Summer Sale', $p->getName());
        self::assertInstanceOf(\DateTimeImmutable::class, $p->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testDescriptionAccessors(): void
    {
        $p = new Promotion();
        $p->setDescription('Desc');
        self::assertSame('Desc', $p->getDescription());
        $p->setDescription(null);
        self::assertNull($p->getDescription());
    }

    #[Group('low-value')]
    public function testTemplateAccessors(): void
    {
        $p = new Promotion();
        $t = new PromotionTemplate();
        $p->setTemplate($t);

        self::assertSame($t, $p->getTemplate());
        $p->setTemplate(null);
        self::assertNull($p->getTemplate());
    }

    #[Group('low-value')]
    public function testStoreCodeAccessors(): void
    {
        $p = new Promotion();
        $p->setStoreCode('store-a');

        self::assertSame('store-a', $p->getStoreCode());
    }

    #[Group('low-value')]
    public function testEnabledAccessors(): void
    {
        $p = new Promotion();
        $p->setEnabled(true);

        self::assertTrue($p->isEnabled());
    }

    #[Group('low-value')]
    public function testStartTimeAccessors(): void
    {
        $p = new Promotion();
        $date = new \DateTimeImmutable('2026-07-01');
        $p->setStartTime($date);

        self::assertSame($date, $p->getStartTime());
        $p->setStartTime(null);
        self::assertNull($p->getStartTime());
    }

    #[Group('low-value')]
    public function testEndTimeAccessors(): void
    {
        $p = new Promotion();
        $date = new \DateTimeImmutable('2026-07-31');
        $p->setEndTime($date);

        self::assertSame($date, $p->getEndTime());
        $p->setEndTime(null);
        self::assertNull($p->getEndTime());
    }

    #[Group('low-value')]
    public function testConfigAccessors(): void
    {
        $p = new Promotion();
        $config = ['threshold' => 200.00, 'amount' => 20.00];
        $p->setConfig($config);

        self::assertSame($config, $p->getConfig());
        $p->setConfig(null);
        self::assertNull($p->getConfig());
    }

    #[Group('low-value')]
    public function testConflictModeAccessors(): void
    {
        $p = new Promotion();

        $p->setConflictMode(Promotion::CONFLICT_EXCLUSIVE);
        self::assertSame(Promotion::CONFLICT_EXCLUSIVE, $p->getConflictMode());

        $p->setConflictMode(Promotion::CONFLICT_LOCK_ITEM);
        self::assertSame(Promotion::CONFLICT_LOCK_ITEM, $p->getConflictMode());

        $p->setConflictMode(Promotion::CONFLICT_STACKABLE);
        self::assertSame(Promotion::CONFLICT_STACKABLE, $p->getConflictMode());
    }

    #[Group('low-value')]
    public function testConflictModeConstants(): void
    {
        self::assertSame('stackable', Promotion::CONFLICT_STACKABLE);
        self::assertSame('exclusive', Promotion::CONFLICT_EXCLUSIVE);
        self::assertSame('lock_item', Promotion::CONFLICT_LOCK_ITEM);
    }

    public function testTouch(): void
    {
        $p = new Promotion();
        self::assertNull($p->getUpdatedAt());
        $p->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $p->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testToString(): void
    {
        $p = new Promotion();
        $p->setName('Summer Sale');

        self::assertSame('Summer Sale', (string) $p);
    }

    #[Group('low-value')]
    public function testUuidIsUnique(): void
    {
        $p1 = new Promotion();
        $p2 = new Promotion();

        self::assertNotSame($p1->getUuid(), $p2->getUuid());
    }
}
