<?php

namespace App\Tests\UnitTest\Common\Entity;

use App\Common\Entity\Page;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $entity = new Page('Test Page', 'test-page');

        self::assertSame('Test Page', $entity->getTitle());
        self::assertSame('test-page', $entity->getSlug());
        self::assertNull($entity->getBody());
        self::assertNull($entity->getMetaTitle());
        self::assertNull($entity->getMetaDescription());
        self::assertSame('draft', $entity->getStatus());
        self::assertNull($entity->getPublishedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertSame('Test Page', (string) $entity);
    }

    #[Group('low-value')]
    public function testSettersAreFluent(): void
    {
        $entity = new Page('before', 'before');

        $entity->setTitle('after')->setSlug('after-slug')
            ->setBody('body text')->setMetaTitle('meta')->setMetaDescription('meta desc')
            ->setStatus('published');

        self::assertSame('after', $entity->getTitle());
        self::assertSame('after-slug', $entity->getSlug());
        self::assertSame('body text', $entity->getBody());
        self::assertSame('meta', $entity->getMetaTitle());
        self::assertSame('meta desc', $entity->getMetaDescription());
        self::assertSame('published', $entity->getStatus());
    }

    #[Group('low-value')]
    public function testPublishedAtSetter(): void
    {
        $entity = new Page('title', 'slug');
        $date = new \DateTimeImmutable('2025-01-01');

        $entity->setPublishedAt($date);
        self::assertSame($date, $entity->getPublishedAt());

        $entity->setPublishedAt(null);
        self::assertNull($entity->getPublishedAt());
    }

    public function testTouchUpdatesTimestamp(): void
    {
        $entity = new Page('name', 'slug');
        self::assertNull($entity->getUpdatedAt());

        $entity->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testNullableFieldsAcceptNull(): void
    {
        $entity = new Page('title', 'slug');
        $entity->setBody('some body');
        $entity->setMetaTitle('meta title');
        $entity->setMetaDescription('meta desc');

        $entity->setBody(null)->setMetaTitle(null)->setMetaDescription(null);

        self::assertNull($entity->getBody());
        self::assertNull($entity->getMetaTitle());
        self::assertNull($entity->getMetaDescription());
    }

    public function testPrePersistWhenCreatedFromReflection(): void
    {
        $reflection = new \ReflectionClass(Page::class);
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistPreservesExistingCreatedAt(): void
    {
        $entity = new Page('preserve', 'preserve');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();
        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
