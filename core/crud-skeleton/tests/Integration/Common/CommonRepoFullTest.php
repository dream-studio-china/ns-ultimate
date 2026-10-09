<?php

declare(strict_types=1);

namespace App\Tests\Integration\Common;

use App\Common\Entity\Category;
use App\Common\Entity\Comment;
use App\Common\Entity\Content;
use App\Common\Entity\Media;
use App\Common\Entity\Page;
use App\Common\Entity\Setting;
use App\Common\Entity\Tag;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

final class CommonRepoFullTest extends IntegrationKernelTestCase
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
        $this->em->clear();
    }

    public function testCategoryFindBySlug(): void
    {
        $cat = new Category('Tech', 'technology');
        $this->em->persist($cat);
        $this->em->flush();

        $repo = $this->em->getRepository(Category::class);
        self::assertNotNull($repo->findBySlug('technology'));
        self::assertNull($repo->findBySlug('nonexistent'));
    }

    public function testCategoryFindRootCategories(): void
    {
        $root = new Category('Root', 'root');
        $child = new Category('Child', 'child');
        $child->setParent($root);
        $this->em->persist($root);
        $this->em->persist($child);
        $this->em->flush();

        $repo = $this->em->getRepository(Category::class);
        $roots = $repo->findRootCategories();
        self::assertCount(1, $roots);
        self::assertSame('Root', $roots[0]->getName());
    }

    public function testCategoryFindEnabled(): void
    {
        $enabled = new Category('On', 'on');
        $disabled = new Category('Off', 'off');
        $disabled->setEnabled(false);
        $this->em->persist($enabled);
        $this->em->persist($disabled);
        $this->em->flush();

        $repo = $this->em->getRepository(Category::class);
        $results = $repo->findEnabled();
        self::assertCount(1, $results);
        self::assertSame('On', $results[0]->getName());
    }

    public function testCommentFindByEntity(): void
    {
        $approved = new Comment('Good', 'content', 10);
        $approved->setStatus('approved');
        $pending = new Comment('Bad', 'content', 10);
        $pending->setStatus('pending');
        $otherType = new Comment('Ok', 'page', 10);
        $otherType->setStatus('approved');
        $this->em->persist($approved);
        $this->em->persist($pending);
        $this->em->persist($otherType);
        $this->em->flush();

        $repo = $this->em->getRepository(Comment::class);
        $results = $repo->findByEntity('content', 10);
        self::assertCount(1, $results);
        self::assertSame('Good', $results[0]->getBody());
    }

    public function testCommentFindPending(): void
    {
        $pending = new Comment('Pending', 'content', 1);
        $pending->setStatus('pending');
        $approved = new Comment('Approved', 'content', 1);
        $approved->setStatus('approved');
        $this->em->persist($pending);
        $this->em->persist($approved);
        $this->em->flush();

        $repo = $this->em->getRepository(Comment::class);
        $results = $repo->findPending();
        self::assertCount(1, $results);
        self::assertSame('Pending', $results[0]->getBody());
    }

    #[Group('low-value')]
    public function testContentFindLatest(): void
    {
        $old = new Content('Old');
        $new = new Content('New');
        $this->em->persist($old);
        $this->em->persist($new);
        $this->em->flush();

        $repo = $this->em->getRepository(Content::class);
        $latest = $repo->findLatest(1);
        self::assertCount(1, $latest);
    }

    #[Group('low-value')]
    public function testContentFindLatestWithDefaultLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->em->persist(new Content("Post $i"));
        }
        $this->em->flush();

        $repo = $this->em->getRepository(Content::class);
        $latest = $repo->findLatest();
        self::assertCount(5, $latest);
    }

    public function testMediaFindImages(): void
    {
        $img = new Media('photo.jpg', 'photo.jpg', 'image/jpeg', 500, '/uploads/photo.jpg');
        $doc = new Media('doc.pdf', 'doc.pdf', 'application/pdf', 200, '/uploads/doc.pdf');
        $this->em->persist($img);
        $this->em->persist($doc);
        $this->em->flush();

        $repo = $this->em->getRepository(Media::class);
        $images = $repo->findImages();
        self::assertCount(1, $images);
        self::assertSame('photo.jpg', $images[0]->getFilename());
    }

    public function testPageFindBySlug(): void
    {
        $page = new Page('About Us', 'about');
        $this->em->persist($page);
        $this->em->flush();

        $repo = $this->em->getRepository(Page::class);
        self::assertNotNull($repo->findBySlug('about'));
        self::assertNull($repo->findBySlug('no-slug'));
    }

    public function testPageFindPublished(): void
    {
        $pub = new Page('Published', 'pub');
        $pub->setStatus('published');
        $draft = new Page('Draft', 'draft');
        $draft->setStatus('draft');
        $this->em->persist($pub);
        $this->em->persist($draft);
        $this->em->flush();

        $repo = $this->em->getRepository(Page::class);
        $results = $repo->findPublished();
        self::assertCount(1, $results);
        self::assertSame('Published', $results[0]->getTitle());
    }

    public function testSettingFindByKey(): void
    {
        $setting = new Setting('site.name');
        $setting->setValue('My Site');
        $this->em->persist($setting);
        $this->em->flush();

        $repo = $this->em->getRepository(Setting::class);
        $found = $repo->findByKey('site.name');
        self::assertNotNull($found);
        self::assertSame('My Site', $found->getValue());
        self::assertNull($repo->findByKey('missing'));
    }

    public function testSettingFindByGroup(): void
    {
        $s1 = new Setting('app.name');
        $s1->setGroupName('general');
        $s2 = new Setting('app.version');
        $s2->setGroupName('general');
        $s3 = new Setting('db.host');
        $s3->setGroupName('database');
        $this->em->persist($s1);
        $this->em->persist($s2);
        $this->em->persist($s3);
        $this->em->flush();

        $repo = $this->em->getRepository(Setting::class);
        $general = $repo->findByGroup('general');
        self::assertCount(2, $general);
        $db = $repo->findByGroup('database');
        self::assertCount(1, $db);
        $empty = $repo->findByGroup('none');
        self::assertCount(0, $empty);
    }

    public function testTagFindBySlug(): void
    {
        $tag = new Tag('PHP', 'php');
        $this->em->persist($tag);
        $this->em->flush();

        $repo = $this->em->getRepository(Tag::class);
        self::assertNotNull($repo->findBySlug('php'));
        self::assertNull($repo->findBySlug('no-slug'));
    }

    public function testTagFindByNameLike(): void
    {
        $t1 = new Tag('Symfony', 'symfony');
        $t2 = new Tag('Laravel', 'laravel');
        $t3 = new Tag('Physics', 'physics');
        $this->em->persist($t1);
        $this->em->persist($t2);
        $this->em->persist($t3);
        $this->em->flush();

        $repo = $this->em->getRepository(Tag::class);
        $results = $repo->findByNameLike('y');
        self::assertCount(2, $results);
    }
}
