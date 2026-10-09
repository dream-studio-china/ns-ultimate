<?php

namespace App\Tests\UnitTest\Common\Entity;

use App\Common\Entity\Content;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    public function testConstructorInitializesCoreFields(): void
    {
        $entity = new Content('hello-title', 'hello-body');

        self::assertSame('hello-title', $entity->getTitle());
        self::assertSame('hello-body', $entity->getBody());
        self::assertNull($entity->getCategory());
        self::assertEmpty($entity->getTags());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertSame('hello-title', (string) $entity);
    }

    #[Group('low-value')]
    public function testSettersAreFluentAndTouchUpdatesTimestamp(): void
    {
        $entity = new Content('before');

        $updated = $entity->setTitle('after')->setBody('new-body');

        self::assertSame($entity, $updated);
        self::assertSame('after', $entity->getTitle());
        self::assertSame('new-body', $entity->getBody());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    #[DataProvider('bodyProvider')]
    #[Group('low-value')]
    public function testSetBodySupportsNullableValues(?string $body): void
    {
        $entity = new Content('title', 'initial');

        $entity->setBody($body);

        self::assertSame($body, $entity->getBody());
        self::assertNotNull($entity->getUpdatedAt());
    }

    public static function bodyProvider(): array
    {
        return [
            'string body' => ['text-body'],
            'null body' => [null],
        ];
    }

    public function testPrePersistInitializesCreatedAtWhenConstructorWasSkipped(): void
    {
        $reflection = new \ReflectionClass(Content::class);
        /** @var Content $entity */
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();

        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistKeepsCreatedAtWhenAlreadySet(): void
    {
        $entity = new Content('t');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();

        self::assertSame($createdAt, $entity->getCreatedAt());
    }

    public function testCategoryRelationship(): void
    {
        $content = new Content('title');
        $category = new \App\Common\Entity\Category('My Category', 'my-category');

        $content->setCategory($category);
        self::assertSame($category, $content->getCategory());

        $content->setCategory(null);
        self::assertNull($content->getCategory());
    }

    public function testTagRelationships(): void
    {
        $content = new Content('title');
        $tag1 = new \App\Common\Entity\Tag('Tag 1', 'tag-1');
        $tag2 = new \App\Common\Entity\Tag('Tag 2', 'tag-2');

        $content->addTag($tag1);
        $content->addTag($tag2);

        self::assertCount(2, $content->getTags());
        self::assertTrue($content->getTags()->contains($tag1));
        self::assertTrue($content->getTags()->contains($tag2));

        $content->removeTag($tag1);
        self::assertCount(1, $content->getTags());
        self::assertFalse($content->getTags()->contains($tag1));
        self::assertTrue($content->getTags()->contains($tag2));
    }

    public function testAddTagDoesNotDuplicate(): void
    {
        $content = new Content('title');
        $tag = new \App\Common\Entity\Tag('Tag', 'tag');

        $content->addTag($tag);
        $content->addTag($tag);

        self::assertCount(1, $content->getTags());
    }
}
