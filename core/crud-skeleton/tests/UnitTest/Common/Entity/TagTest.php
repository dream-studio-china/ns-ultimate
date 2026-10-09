<?php

namespace App\Tests\UnitTest\Common\Entity;

use App\Common\Entity\Tag;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $entity = new Tag('My Tag', 'my-tag');

        self::assertSame('My Tag', $entity->getName());
        self::assertSame('my-tag', $entity->getSlug());
        self::assertNull($entity->getColor());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertSame('My Tag', (string) $entity);
    }

    #[Group('low-value')]
    public function testSettersAreFluent(): void
    {
        $entity = new Tag('before', 'before');

        $entity->setName('after')->setSlug('after-slug')->setColor('#ff0000');

        self::assertSame('after', $entity->getName());
        self::assertSame('after-slug', $entity->getSlug());
        self::assertSame('#ff0000', $entity->getColor());
    }

    public function testTouchUpdatesTimestamp(): void
    {
        $entity = new Tag('name', 'slug');
        self::assertNull($entity->getUpdatedAt());

        $entity->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testSetColorSupportsNull(): void
    {
        $entity = new Tag('name', 'slug');
        $entity->setColor('#ffffff');
        self::assertSame('#ffffff', $entity->getColor());

        $entity->setColor(null);
        self::assertNull($entity->getColor());
    }

    public function testPrePersistWhenCreatedFromReflection(): void
    {
        $reflection = new \ReflectionClass(Tag::class);
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistPreservesExistingCreatedAt(): void
    {
        $entity = new Tag('preserve', 'preserve');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();
        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
