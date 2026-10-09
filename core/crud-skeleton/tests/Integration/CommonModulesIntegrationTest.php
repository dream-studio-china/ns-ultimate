<?php

namespace App\Tests\Integration;

use App\Common\Entity\Category;
use App\Common\Entity\Comment;
use App\Common\Entity\Media;
use App\Common\Entity\Page;
use App\Common\Entity\Setting;
use App\Common\Entity\Tag;
use App\Core\Service\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class CommonModulesIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $tables = [
            'App\\Common\\Entity\\Comment',
            'App\\Common\\Entity\\Setting',
            'App\\Common\\Entity\\Page',
            'App\\Common\\Entity\\Media',
            'App\\Common\\Entity\\Tag',
            'App\\Common\\Entity\\Category',
        ];

        foreach ($tables as $table) {
            $this->em->createQuery("DELETE FROM $table")->execute();
        }
    }

    // --- Category Tests ---

    public function testCategoryCrudFlow(): void
    {
        $service = new TestBaseService(static::getContainer(), Category::class);

        $created = $service->update(new Category('Root', 'root'), ['name' => 'Root', 'slug' => 'root']);
        self::assertInstanceOf(Category::class, $created);
        self::assertNotNull($created->getId());
        self::assertSame('Root', $created->getName());

        $fetched = $service->get($created->getId());
        self::assertInstanceOf(Category::class, $fetched);
        self::assertSame('Root', $fetched->getName());

        $updated = $service->update($fetched, ['name' => 'Updated Root', 'description' => 'A description']);
        self::assertSame('Updated Root', $updated->getName());
        self::assertSame('A description', $updated->getDescription());

        $listed = $service->list(null, null, true);
        self::assertIsArray($listed);
        self::assertNotEmpty($listed);

        $child = $service->update(new Category('Child', 'child'), ['name' => 'Child', 'slug' => 'child']);
        $child->setParent($created);
        $this->em->persist($child);
        $this->em->flush();

        $this->em->refresh($created);
        $this->em->refresh($child);
        $fetchedParent = $service->get($created->getId());
        self::assertCount(1, $fetchedParent->getChildren());
        self::assertSame($fetchedParent, $child->getParent());

        self::assertTrue($service->remove($child->getId()));
        self::assertTrue($service->remove($created->getId()));
    }

    // --- Tag Tests ---

    public function testTagCrudFlow(): void
    {
        $service = new TestBaseService(static::getContainer(), Tag::class);

        $created = $service->update(new Tag('PHP', 'php'), ['name' => 'PHP', 'slug' => 'php', 'color' => '#777bb3']);
        self::assertInstanceOf(Tag::class, $created);
        self::assertSame('PHP', $created->getName());
        self::assertSame('#777bb3', $created->getColor());

        $tag2 = $service->update(new Tag('Symfony', 'symfony'), ['name' => 'Symfony', 'slug' => 'symfony']);
        self::assertInstanceOf(Tag::class, $tag2);

        $listed = $service->list(null, null, true);
        self::assertIsArray($listed);
        self::assertCount(2, $listed);

        $updated = $service->update($created, ['name' => 'PHP 8', 'color' => '#4f5b93']);
        self::assertSame('PHP 8', $updated->getName());
        self::assertSame('#4f5b93', $updated->getColor());

        self::assertTrue($service->remove($created->getId()));
        self::assertTrue($service->remove($tag2->getId()));
    }

    // --- Media Tests ---

    public function testMediaCrudFlow(): void
    {
        $service = new TestBaseService(static::getContainer(), Media::class);

        $created = $service->update(
            new Media('img.jpg', 'photo.jpg', 'image/jpeg', 2048, '/uploads/img.jpg'),
            [
                'filename' => 'img.jpg',
                'originalFilename' => 'photo.jpg',
                'mimeType' => 'image/jpeg',
                'size' => 2048,
                'path' => '/uploads/img.jpg',
            ]
        );
        self::assertInstanceOf(Media::class, $created);
        self::assertNotNull($created->getId());
        self::assertSame('img.jpg', $created->getFilename());

        $updated = $service->update($created, ['alt' => 'My image', 'title' => 'Image Title', 'width' => 1920, 'height' => 1080]);
        self::assertSame('My image', $updated->getAlt());
        self::assertSame('Image Title', $updated->getTitle());
        self::assertSame(1920, $updated->getWidth());
        self::assertSame(1080, $updated->getHeight());

        self::assertTrue($service->remove($created->getId()));
    }

    // --- Page Tests ---

    public function testPageCrudFlow(): void
    {
        $service = new TestBaseService(static::getContainer(), Page::class);

        $created = $service->update(
            new Page('About Us', 'about-us'),
            ['title' => 'About Us', 'slug' => 'about-us', 'body' => 'About page body']
        );
        self::assertInstanceOf(Page::class, $created);
        self::assertSame('About Us', $created->getTitle());
        self::assertSame('draft', $created->getStatus());

        $updated = $service->update($created, [
            'status' => 'published',
            'publishedAt' => new \DateTimeImmutable('2025-01-01'),
            'metaTitle' => 'About | Site',
            'metaDescription' => 'Learn about us',
        ]);
        self::assertSame('published', $updated->getStatus());
        self::assertNotNull($updated->getPublishedAt());
        self::assertSame('About | Site', $updated->getMetaTitle());
        self::assertSame('Learn about us', $updated->getMetaDescription());

        $listed = $service->list(null, null, true);
        self::assertIsArray($listed);
        self::assertCount(1, $listed);

        self::assertTrue($service->remove($created->getId()));
    }

    // --- Comment Tests ---

    public function testCommentCrudFlow(): void
    {
        $service = new TestBaseService(static::getContainer(), Comment::class);

        $created = $service->update(
            new Comment('Nice content!', 'Page', 1),
            [
                'body' => 'Nice content!',
                'entityType' => 'Page',
                'entityId' => 1,
                'authorName' => 'John',
                'authorEmail' => 'john@test.com',
            ]
        );
        self::assertInstanceOf(Comment::class, $created);
        self::assertSame('Nice content!', $created->getBody());
        self::assertSame('pending', $created->getStatus());
        self::assertSame('John', $created->getAuthorName());

        $updated = $service->update($created, [
            'status' => 'approved',
            'body' => 'Nice content, thanks!',
        ]);
        self::assertSame('approved', $updated->getStatus());
        self::assertSame('Nice content, thanks!', $updated->getBody());

        $listed = $service->list(null, null, true);
        self::assertIsArray($listed);
        self::assertCount(1, $listed);

        self::assertTrue($service->remove($created->getId()));
    }

    // --- Setting Tests ---

    public function testSettingCrudFlow(): void
    {
        $service = new TestBaseService(static::getContainer(), Setting::class);

        $created = $service->update(
            new Setting('site_name'),
            [
                'key' => 'site_name',
                'value' => 'My Site',
                'type' => 'string',
                'groupName' => 'general',
                'label' => 'Site Name',
            ]
        );
        self::assertInstanceOf(Setting::class, $created);
        self::assertSame('site_name', $created->getKey());
        self::assertSame('My Site', $created->getValue());
        self::assertSame('general', $created->getGroupName());

        $setting2 = $service->update(
            new Setting('items_per_page'),
            [
                'key' => 'items_per_page',
                'value' => '20',
                'type' => 'integer',
                'groupName' => 'pagination',
            ]
        );
        self::assertInstanceOf(Setting::class, $setting2);

        $updated = $service->update($created, ['value' => 'New Site Name']);
        self::assertSame('New Site Name', $updated->getValue());

        $listed = $service->list(null, null, true);
        self::assertIsArray($listed);
        self::assertCount(2, $listed);

        self::assertTrue($service->remove($created->getId()));
        self::assertTrue($service->remove($setting2->getId()));
    }

    // --- Cross-module Tests ---

    public function testCategoryHierarchy(): void
    {
        $service = new TestBaseService(static::getContainer(), Category::class);

        $root = $service->update(new Category('Electronics', 'electronics'), ['name' => 'Electronics', 'slug' => 'electronics']);
        $sub = $service->update(new Category('Phones', 'phones'), ['name' => 'Phones', 'slug' => 'phones']);
        $subsub = $service->update(new Category('Smartphones', 'smartphones'), ['name' => 'Smartphones', 'slug' => 'smartphones']);

        $sub->setParent($root);
        $subsub->setParent($sub);

        $this->em->persist($sub);
        $this->em->persist($subsub);
        $this->em->flush();

        $this->em->refresh($root);
        $this->em->refresh($sub);
        $fetchedRoot = $service->get($root->getId());
        self::assertCount(1, $fetchedRoot->getChildren());
        self::assertSame('Phones', $fetchedRoot->getChildren()->first()->getName());

        $fetchedSub = $service->get($sub->getId());
        self::assertCount(1, $fetchedSub->getChildren());
        self::assertSame('Smartphones', $fetchedSub->getChildren()->first()->getName());

        self::assertTrue($service->remove($subsub->getId()));
        self::assertTrue($service->remove($sub->getId()));
        self::assertTrue($service->remove($root->getId()));
    }

    public function testUpdateForAllModuleTypes(): void
    {
        $modules = [
            ['class' => Category::class, 'create' => ['name' => 'cat-name', 'slug' => 'cat-slug'], 'update' => ['name' => 'updated-cat']],
            ['class' => Tag::class, 'create' => ['name' => 'tag-name', 'slug' => 'tag-slug'], 'update' => ['name' => 'updated-tag']],
            ['class' => Page::class, 'create' => ['title' => 'Page Title', 'slug' => 'page-slug'], 'update' => ['title' => 'updated-page']],
            ['class' => Setting::class, 'create' => ['key' => 'setting_key', 'value' => 'val'], 'update' => ['value' => 'updated-val']],
        ];

        foreach ($modules as $module) {
            $service = new TestBaseService(static::getContainer(), $module['class']);

            $entity = $service->new();
            $created = $service->update($entity, $module['create']);
            self::assertNotNull($created, "Failed to create {$module['class']}");
            self::assertNotNull($created->getId());

            $updated = $service->update($created, $module['update']);
            self::assertNotNull($updated);

            self::assertTrue($service->remove($created->getId()));
        }
    }
}

final class TestBaseService extends BaseService
{
    public function __construct(ContainerInterface $container, string $entityClass)
    {
        parent::__construct($container, $entityClass);
    }
}
