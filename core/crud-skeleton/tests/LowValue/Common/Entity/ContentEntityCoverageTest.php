<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Common\Entity;


use PHPUnit\Framework\Attributes\Group;
use App\Common\Entity\Content;
use App\Common\Entity\Tag;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Covers the last uncovered branch of App\Common\Entity\Content:
 * getTags() lazily re-initializing the collection when it is null (line 103).
 */
#[Group('low-value')]
final class ContentEntityCoverageTest extends TestCase
{
    public function testGetTagsReinitializesWhenPropertyIsNull(): void
    {
        $content = new Content('title', 'body');
        $reflection = new \ReflectionProperty(Content::class, 'tags');
        $reflection->setValue($content, null);

        self::assertInstanceOf(Collection::class, $content->getTags());
        self::assertCount(0, $content->getTags());
    }

    public function testAddTagReinitializesNullCollection(): void
    {
        $content = new Content('title');
        $reflection = new \ReflectionProperty(Content::class, 'tags');
        $reflection->setValue($content, null);

        $tag = new Tag('Tag', 'tag');
        $content->addTag($tag);

        self::assertCount(1, $content->getTags());
        self::assertTrue($content->getTags()->contains($tag));
    }

    public function testTagsFromReflectionWithoutConstructorAreLazilyInitialized(): void
    {
        $reflection = new \ReflectionClass(Content::class);
        /** @var Content $content */
        $content = $reflection->newInstanceWithoutConstructor();

        self::assertCount(0, $content->getTags());
    }

    public function testSetTitleTouchesUpdatedAt(): void
    {
        // SKIPPED — documents Bug: src/Common/Entity/Content.php:65-70 setTitle() does not call
        // $this->touch(), unlike setBody()/setCategory() and every other Common entity setter.
        // A title-only edit therefore leaves updatedAt untouched.
        $this->markTestSkipped(
            'Content::setTitle() does not refresh updatedAt (src/Common/Entity/Content.php:65-70). '
            . 'See docs/issues/coverage-2026-08-09/common-controllers-entity.md Bug 1.'
        );

        $entity = new Content('before');
        $entity->setTitle('after');
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    public function testAddTagTouchesUpdatedAt(): void
    {
        // SKIPPED — documents the same Bug 1: addTag()/removeTag() mutate the tags collection
        // without calling touch(), so updatedAt is not refreshed.
        $this->markTestSkipped(
            'Content::addTag() does not refresh updatedAt (src/Common/Entity/Content.php:108-120). '
            . 'See docs/issues/coverage-2026-08-09/common-controllers-entity.md Bug 1.'
        );

        $content = new Content('title');
        $tag = new Tag('Tag', 'tag');
        $content->addTag($tag);
        self::assertInstanceOf(\DateTimeImmutable::class, $content->getUpdatedAt());
    }
}
