<?php

namespace App\Tests\UnitTest\Common\Entity;

use App\Common\Entity\Comment;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $entity = new Comment('Great content!', 'Content', 42);

        self::assertSame('Great content!', $entity->getBody());
        self::assertSame('Content', $entity->getEntityType());
        self::assertSame(42, $entity->getEntityId());
        self::assertNull($entity->getAuthorName());
        self::assertNull($entity->getAuthorEmail());
        self::assertNull($entity->getAuthor());
        self::assertNull($entity->getParent());
        self::assertSame('pending', $entity->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertStringStartsWith('Great content!', (string) $entity);
    }

    #[Group('low-value')]
    public function testSettersAreFluent(): void
    {
        $entity = new Comment('body', 'Page', 1);

        $entity->setBody('updated body')->setAuthorName('John')
            ->setAuthorEmail('john@example.com')->setEntityType('Content')
            ->setEntityId(10)->setStatus('approved');

        self::assertSame('updated body', $entity->getBody());
        self::assertSame('John', $entity->getAuthorName());
        self::assertSame('john@example.com', $entity->getAuthorEmail());
        self::assertSame('Content', $entity->getEntityType());
        self::assertSame(10, $entity->getEntityId());
        self::assertSame('approved', $entity->getStatus());
    }

    public function testAuthorRelationship(): void
    {
        $entity = new Comment('body', 'Page', 1);
        $user = new User();
        $user->setEmail('test@example.com');

        $entity->setAuthor($user);
        self::assertSame($user, $entity->getAuthor());

        $entity->setAuthor(null);
        self::assertNull($entity->getAuthor());
    }

    public function testParentRelationship(): void
    {
        $parent = new Comment('parent comment', 'Page', 1);
        $child = new Comment('child comment', 'Page', 1);

        $child->setParent($parent);
        self::assertSame($parent, $child->getParent());

        $child->setParent(null);
        self::assertNull($child->getParent());
    }

    public function testTouchUpdatesTimestamp(): void
    {
        $entity = new Comment('body', 'Page', 1);
        self::assertNull($entity->getUpdatedAt());

        $entity->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    public function testToStringTruncatesLongBody(): void
    {
        $long = str_repeat('a', 100);
        $entity = new Comment($long, 'Page', 1);

        self::assertSame(50, mb_strlen((string) $entity));
    }

    public function testPrePersistWhenCreatedFromReflection(): void
    {
        $reflection = new \ReflectionClass(Comment::class);
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistPreservesExistingCreatedAt(): void
    {
        $entity = new Comment('preserve', 'Page', 1);
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();
        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
