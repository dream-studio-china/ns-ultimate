<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Common;


use PHPUnit\Framework\Attributes\Group;
use App\Common\Entity\Category;
use App\Common\Entity\Comment;
use App\Common\Entity\Content;
use App\Common\Entity\Media;
use App\Common\Entity\Page;
use App\Common\Entity\Setting;
use App\Common\Entity\Tag;
use App\Common\Repository\CategoryRepository;
use App\Common\Repository\CommentRepository;
use App\Common\Repository\ContentRepository;
use App\Common\Repository\MediaRepository;
use App\Common\Repository\PageRepository;
use App\Common\Repository\SettingRepository;
use App\Common\Repository\TagRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

#[Group('low-value')]
final class CommonRepoTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $tables = [Content::class, Comment::class, Setting::class, Page::class, Media::class, Tag::class, Category::class];
        foreach ($tables as $table) {
            $this->em->createQuery("DELETE FROM $table")->execute();
        }
    }

    public function testContentRepositoryFindByIdAndFindAll(): void
    {
        $content = new Content('Test Title', 'Test body text');
        $this->em->persist($content);
        $this->em->flush();

        $repo = $this->em->getRepository(Content::class);
        $found = $repo->findById((int) $content->getId());
        self::assertInstanceOf(Content::class, $found);
        self::assertSame('Test Title', $found->getTitle());

        $all = $repo->findAll();
        self::assertIsArray($all);
        self::assertNotEmpty($all);
    }

    public function testCategoryRepositoryFindByIdAndFindAll(): void
    {
        $category = new Category('Technology', 'technology');
        $this->em->persist($category);
        $this->em->flush();

        $repo = $this->em->getRepository(Category::class);
        $found = $repo->findById((int) $category->getId());
        self::assertInstanceOf(Category::class, $found);
        self::assertSame('Technology', $found->getName());
        self::assertNotEmpty($repo->findAll());
    }

    public function testCommentRepositoryFindByIdAndFindAll(): void
    {
        $comment = new Comment('Great article!', 'Content', 1);
        $this->em->persist($comment);
        $this->em->flush();

        $repo = $this->em->getRepository(Comment::class);
        $found = $repo->findById((int) $comment->getId());
        self::assertInstanceOf(Comment::class, $found);
        self::assertSame('Great article!', $found->getBody());
        self::assertNotEmpty($repo->findAll());
    }

    public function testMediaRepositoryFindByIdAndFindAll(): void
    {
        $media = new Media('test.png', 'original.png', 'image/png', 1024, '/uploads/test.png');
        $this->em->persist($media);
        $this->em->flush();

        $repo = $this->em->getRepository(Media::class);
        $found = $repo->findById((int) $media->getId());
        self::assertInstanceOf(Media::class, $found);
        self::assertSame('test.png', $found->getFilename());
        self::assertNotEmpty($repo->findAll());
    }

    public function testPageRepositoryFindByIdAndFindAll(): void
    {
        $page = new Page('Contact', 'contact');
        $this->em->persist($page);
        $this->em->flush();

        $repo = $this->em->getRepository(Page::class);
        $found = $repo->findById((int) $page->getId());
        self::assertInstanceOf(Page::class, $found);
        self::assertSame('Contact', $found->getTitle());
        self::assertNotEmpty($repo->findAll());
    }

    public function testSettingRepositoryFindByIdAndFindAll(): void
    {
        $setting = new Setting('app_version');
        $setting->setValue('2.0.0');
        $this->em->persist($setting);
        $this->em->flush();

        $repo = $this->em->getRepository(Setting::class);
        $found = $repo->findById((int) $setting->getId());
        self::assertInstanceOf(Setting::class, $found);
        self::assertSame('app_version', $found->getKey());
        self::assertSame('2.0.0', $found->getValue());
        self::assertNotEmpty($repo->findAll());
    }

    public function testTagRepositoryFindByIdAndFindAll(): void
    {
        $tag = new Tag('Laravel', 'laravel');
        $this->em->persist($tag);
        $this->em->flush();

        $repo = $this->em->getRepository(Tag::class);
        $found = $repo->findById((int) $tag->getId());
        self::assertInstanceOf(Tag::class, $found);
        self::assertSame('Laravel', $found->getName());
        self::assertNotEmpty($repo->findAll());
    }
}
