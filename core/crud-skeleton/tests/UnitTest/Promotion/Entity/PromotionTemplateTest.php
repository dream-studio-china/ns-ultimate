<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Entity;

use App\Promotion\Entity\PromotionTemplate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PromotionTemplateTest extends TestCase
{
    #[Group('low-value')]
    public function testConstructorInitializesCoreFields(): void
    {
        $t = new PromotionTemplate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $t->getUuid()
        );
        self::assertSame('', $t->getName());
        self::assertNull($t->getDescription());
        self::assertSame(PromotionTemplate::TYPE_FULL_REDUCTION, $t->getType());
        self::assertSame(PromotionTemplate::PHASE_INNER, $t->getPhase());
        self::assertFalse($t->isEnabled());
        self::assertSame('', $t->getDsl());
        self::assertNull($t->getFields());
        self::assertNull($t->getAstCache());
        self::assertNull($t->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $t->getCreatedAt());
        self::assertNull($t->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testNameAccessors(): void
    {
        $t = new PromotionTemplate();
        $t->setName('Full Reduction');

        self::assertSame('Full Reduction', $t->getName());
        self::assertInstanceOf(\DateTimeImmutable::class, $t->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testDescriptionAccessors(): void
    {
        $t = new PromotionTemplate();
        $t->setDescription('Desc');

        self::assertSame('Desc', $t->getDescription());
        $t->setDescription(null);
        self::assertNull($t->getDescription());
    }

    #[Group('low-value')]
    public function testTypeAccessors(): void
    {
        $t = new PromotionTemplate();
        $t->setType(PromotionTemplate::TYPE_DISCOUNT);

        self::assertSame(PromotionTemplate::TYPE_DISCOUNT, $t->getType());
    }

    #[Group('low-value')]
    public function testPhaseAccessors(): void
    {
        $t = new PromotionTemplate();
        $t->setPhase(PromotionTemplate::PHASE_OUTER);

        self::assertSame(PromotionTemplate::PHASE_OUTER, $t->getPhase());
    }

    #[Group('low-value')]
    public function testEnabledAccessors(): void
    {
        $t = new PromotionTemplate();
        $t->setEnabled(true);

        self::assertTrue($t->isEnabled());
    }

    #[Group('low-value')]
    public function testDslAccessors(): void
    {
        $t = new PromotionTemplate();
        $t->setDsl('type: full_reduction');

        self::assertSame('type: full_reduction', $t->getDsl());
    }

    #[Group('low-value')]
    public function testFieldsAccessors(): void
    {
        $t = new PromotionTemplate();
        $fields = ['threshold' => ['type' => 'number']];
        $t->setFields($fields);

        self::assertSame($fields, $t->getFields());
        $t->setFields(null);
        self::assertNull($t->getFields());
    }

    #[Group('low-value')]
    public function testAstCacheAccessors(): void
    {
        $t = new PromotionTemplate();
        $ast = ['type' => 'program', 'data' => ['type' => 'full_reduction']];
        $t->setAstCache($ast);

        self::assertSame($ast, $t->getAstCache());
        $t->setAstCache(null);
        self::assertNull($t->getAstCache());
    }

    public function testTouch(): void
    {
        $t = new PromotionTemplate();
        self::assertNull($t->getUpdatedAt());
        $t->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $t->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testToString(): void
    {
        $t = new PromotionTemplate();
        $t->setName('Full Reduction');

        self::assertSame('Full Reduction', (string) $t);
    }

    #[Group('low-value')]
    public function testPhaseConstants(): void
    {
        self::assertSame(-1, PromotionTemplate::PHASE_ALL);
        self::assertSame(0, PromotionTemplate::PHASE_INNER);
        self::assertSame(1, PromotionTemplate::PHASE_OUTER);
    }

    #[Group('low-value')]
    public function testTypeConstants(): void
    {
        self::assertSame('full_reduction', PromotionTemplate::TYPE_FULL_REDUCTION);
        self::assertSame('discount', PromotionTemplate::TYPE_DISCOUNT);
        self::assertSame('gift', PromotionTemplate::TYPE_GIFT);
        self::assertSame('nth_discount', PromotionTemplate::TYPE_NTH_DISCOUNT);
        self::assertSame('tiered', PromotionTemplate::TYPE_TIERED);
        self::assertSame('free_shipping', PromotionTemplate::TYPE_FREE_SHIPPING);
        self::assertSame('member_discount', PromotionTemplate::TYPE_MEMBER_DISCOUNT);
    }

    #[Group('low-value')]
    public function testUuidIsUnique(): void
    {
        $t1 = new PromotionTemplate();
        $t2 = new PromotionTemplate();

        self::assertNotSame($t1->getUuid(), $t2->getUuid());
    }
}
